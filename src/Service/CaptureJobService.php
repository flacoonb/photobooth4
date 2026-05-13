<?php

namespace Photobooth\Service;

use Photobooth\Enum\FolderEnum;

class CaptureJobService
{
    private const MAX_AGE_SECONDS = 300;

    public static function jobDir(): string
    {
        return FolderEnum::VAR->absolute() . DIRECTORY_SEPARATOR . 'capture-jobs';
    }

    public static function ensureJobDir(): string
    {
        $dir = self::jobDir();
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not create capture job directory');
        }
        return $dir;
    }

    public static function isValidJobId(string $jobId): bool
    {
        return preg_match('/^[a-f0-9]{32}$/', $jobId) === 1;
    }

    public static function jobFile(string $jobId): string
    {
        return self::ensureJobDir() . DIRECTORY_SEPARATOR . $jobId . '.json';
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function read(string $jobId): ?array
    {
        $path = self::jobFile($jobId);
        clearstatcache(true, $path);
        if (!is_file($path)) {
            return null;
        }
        $content = file_get_contents($path);
        if ($content === false || $content === '') {
            return null;
        }
        $data = json_decode($content, true);
        return is_array($data) ? $data : null;
    }

    /**
     * @param array<string, mixed> $job
     */
    public static function write(array $job): void
    {
        $jobId = (string) ($job['job_id'] ?? '');
        if (!self::isValidJobId($jobId)) {
            throw new \InvalidArgumentException('Invalid capture job id');
        }
        $path = self::jobFile($jobId);
        // Use a unique tmp filename per writer so concurrent writes to the
        // same job (e.g. worker writing status=done while the status endpoint
        // races on the stale check) cannot stomp each other's tmp content.
        // The final atomic rename below is then the only contended step, and
        // POSIX rename(2) is atomic — "last writer wins" without corruption.
        $tmp = $path . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
        // JSON_INVALID_UTF8_SUBSTITUTE protects the persisted state from
        // becoming unstorable when an underlying capture command's stderr
        // produces non-UTF-8 bytes (e.g. localized gphoto2 output, binary
        // garbage from a misbehaving USB device). Without it, json_encode()
        // returns false and the worker's failure-status write itself fails,
        // leaving the job stuck in "running" until the stale-check fires.
        $encoded = json_encode($job, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($encoded === false) {
            throw new \RuntimeException('Failed to encode capture job: ' . json_last_error_msg());
        }
        if (file_put_contents($tmp, $encoded, LOCK_EX) === false) {
            throw new \RuntimeException('Failed to write capture job temp file');
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Failed to finalize capture job file');
        }
    }

    public static function delete(string $jobId): void
    {
        if (!self::isValidJobId($jobId)) {
            return;
        }
        $path = self::jobFile($jobId);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Create a job from the given payload, spawn the worker, return the job ID.
     *
     * If the worker process cannot be spawned (e.g. exec disabled, PHP CLI
     * missing) the job is immediately marked as failed so the polling client
     * gets a useful error without having to wait for the stale timeout.
     *
     * @param array<string, mixed> $payload
     */
    public static function dispatch(array $payload): string
    {
        self::cleanup();

        if (!function_exists('exec')) {
            throw new \RuntimeException('Cannot dispatch capture job: exec() is disabled.');
        }

        $workerScript = self::workerScript();
        if (!is_file($workerScript)) {
            throw new \RuntimeException('Capture worker script not found: ' . $workerScript);
        }

        $jobId = bin2hex(random_bytes(16));
        self::write([
            'job_id' => $jobId,
            'status' => 'queued',
            'created_at' => date(DATE_ATOM),
            'updated_at' => date(DATE_ATOM),
            'payload' => $payload,
        ]);

        $command = escapeshellarg(self::getPhpCliBinary())
            . ' '
            . escapeshellarg($workerScript)
            . ' '
            . escapeshellarg($jobId)
            . ' > /dev/null 2>&1 &';

        $spawnOutput = [];
        $spawnReturn = 0;
        exec($command, $spawnOutput, $spawnReturn);

        // The trailing "&" makes the shell return immediately with exit 0 in
        // virtually all situations; a non-zero return code here only catches
        // shell-level catastrophes (fork failure, FD exhaustion). Worker
        // crashes after spawn surface via the "queued" stale threshold in
        // captureStatus.php — see markSpawnFailed for the queued-only guard.
        if ($spawnReturn !== 0) {
            self::markSpawnFailed($jobId, $spawnReturn, $spawnOutput);
            throw new \RuntimeException(sprintf(
                'Failed to spawn capture worker (exit %d): %s',
                $spawnReturn,
                implode("\n", $spawnOutput) ?: 'no output'
            ));
        }

        return $jobId;
    }

    /**
     * @param array<int, string> $output
     */
    private static function markSpawnFailed(string $jobId, int $exitCode, array $output): void
    {
        $job = self::read($jobId);
        if ($job === null) {
            return;
        }
        // Only flip a still-queued job to failed. If the worker won the race
        // and already wrote status=running (or even done/failed), we must not
        // overwrite its state.
        if (($job['status'] ?? null) !== 'queued') {
            return;
        }
        $job['status'] = 'failed';
        $job['error'] = sprintf(
            'Capture worker could not be started (exit %d): %s',
            $exitCode,
            implode("\n", $output) ?: 'no output'
        );
        $job['updated_at'] = date(DATE_ATOM);
        $job['completed_at'] = date(DATE_ATOM);
        try {
            self::write($job);
        } catch (\Throwable $e) {
            // Swallow — caller throws its own exception and the stale check
            // will eventually clean up the queued entry.
        }
    }

    /**
     * Remove completed/failed job files older than $maxAgeSeconds, as well
     * as any orphaned `*.tmp.*` files left over from a writer that was
     * killed between file_put_contents() and rename().
     */
    public static function cleanup(int $maxAgeSeconds = self::MAX_AGE_SECONDS): void
    {
        $dir = self::jobDir();
        if (!is_dir($dir)) {
            return;
        }
        $now = time();

        $patterns = [
            $dir . DIRECTORY_SEPARATOR . '*.json',
            $dir . DIRECTORY_SEPARATOR . '*.json.tmp.*',
        ];
        foreach ($patterns as $pattern) {
            $files = glob($pattern);
            if (!is_array($files)) {
                continue;
            }
            foreach ($files as $file) {
                if ($now - (int) filemtime($file) > $maxAgeSeconds) {
                    @unlink($file);
                }
            }
        }
    }

    public static function getPhpCliBinary(): string
    {
        // PHP_BINARY in a PHP-FPM / Apache mod_php context points at the SAPI
        // server binary (e.g. /usr/sbin/php-fpm), which is not a CLI and will
        // not run the worker script correctly. Filter out anything that
        // clearly is not a CLI binary before falling back to common paths.
        $candidates = array_filter([
            PHP_SAPI === 'cli' ? PHP_BINARY : null,
            PHP_BINDIR . DIRECTORY_SEPARATOR . 'php',
            PHP_BINDIR . DIRECTORY_SEPARATOR . 'php' . PHP_MAJOR_VERSION,
            PHP_BINDIR . DIRECTORY_SEPARATOR . 'php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            '/usr/bin/php',
            '/usr/local/bin/php',
            '/bin/php',
        ]);

        foreach ($candidates as $candidate) {
            // Reject obvious non-CLI SAPI binaries that might still be on disk.
            $basename = basename($candidate);
            if (str_contains($basename, 'fpm') || str_contains($basename, 'cgi')) {
                continue;
            }
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return 'php';
    }

    private static function workerScript(): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'captureWorker.php';
    }
}
