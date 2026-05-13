<?php

declare(strict_types=1);

namespace Photobooth\Service;

use Photobooth\Logger\NamedLogger;
use Photobooth\Utility\PathUtility;

class UploadQueueService
{
    protected \PDO $db;
    protected NamedLogger $logger;

    public function __construct()
    {
        $this->logger = LoggerService::getInstance()->getLogger('uploadqueue');
        $dbPath = PathUtility::getAbsolutePath('var/run/upload_queue.sqlite');
        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $this->db = new \PDO('sqlite:' . $dbPath);
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // Tune SQLite for the producer/consumer pattern of this queue:
        // - WAL journal: concurrent readers + one writer instead of mutually
        //   exclusive locking. HTTP requests enqueue while the worker is
        //   claiming/updating without either blocking the other.
        // - busy_timeout: wait up to 5s for a lock instead of failing
        //   immediately with SQLITE_BUSY (which surfaces as PDOException to
        //   the photo flow).
        // - synchronous=NORMAL: safe with WAL while avoiding the per-write
        //   fsync of FULL — appropriate for a queue where occasional loss of
        //   the last few seconds on power-loss is acceptable.
        try {
            $this->db->exec('PRAGMA journal_mode=WAL');
            $this->db->exec('PRAGMA busy_timeout=5000');
            $this->db->exec('PRAGMA synchronous=NORMAL');
        } catch (\PDOException $pragmaError) {
            $this->logger->warning('Failed to apply SQLite pragmas', ['error' => $pragmaError->getMessage()]);
        }

        $this->initializeDatabase();
    }

    protected function initializeDatabase(): void
    {
        $this->db->exec('CREATE TABLE IF NOT EXISTS upload_queue (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            image_file TEXT NOT NULL,
            thumb_file TEXT NOT NULL,
            remote_filename TEXT NOT NULL DEFAULT \'\',
            create_webpage INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT \'pending\',
            retries INTEGER NOT NULL DEFAULT 0,
            error_message TEXT,
            next_attempt_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            updated_at TEXT NOT NULL DEFAULT (datetime(\'now\'))
        )');

        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_upload_queue_status ON upload_queue (status)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_upload_queue_image_file ON upload_queue (image_file)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_upload_queue_next_attempt ON upload_queue (status, next_attempt_at)');

        // Migrate: add columns that were introduced after the initial schema.
        // Each ALTER is wrapped in try/catch because two processes booting
        // simultaneously (e.g. systemd worker starting while an HTTP request
        // is enqueueing) can both see the column as missing and race to add
        // it — the loser would otherwise crash with "duplicate column name".
        $existingColumns = [];
        $columns = $this->db->query('PRAGMA table_info(upload_queue)');
        if ($columns !== false) {
            while ($col = $columns->fetch(\PDO::FETCH_ASSOC)) {
                $existingColumns[(string) $col['name']] = true;
            }
        }
        if (!isset($existingColumns['remote_filename'])) {
            try {
                $this->db->exec('ALTER TABLE upload_queue ADD COLUMN remote_filename TEXT NOT NULL DEFAULT \'\'');
            } catch (\PDOException $migrationError) {
                if (!str_contains($migrationError->getMessage(), 'duplicate column')) {
                    throw $migrationError;
                }
            }
        }
        if (!isset($existingColumns['next_attempt_at'])) {
            try {
                // SQLite cannot use non-constant defaults in ALTER TABLE;
                // backfill with the current time so existing pending rows
                // are immediately eligible for retry.
                $this->db->exec('ALTER TABLE upload_queue ADD COLUMN next_attempt_at TEXT NOT NULL DEFAULT \'1970-01-01 00:00:00\'');
                $this->db->exec('UPDATE upload_queue SET next_attempt_at = datetime(\'now\') WHERE next_attempt_at = \'1970-01-01 00:00:00\'');
            } catch (\PDOException $migrationError) {
                if (!str_contains($migrationError->getMessage(), 'duplicate column')) {
                    throw $migrationError;
                }
            }
        }
    }

    public function enqueue(string $imageFile, string $thumbFile, bool $createWebpage): int
    {
        $extension = strtolower(pathinfo($imageFile, PATHINFO_EXTENSION));
        $remoteFilename = bin2hex(random_bytes(16)) . '.' . $extension;

        $stmt = $this->db->prepare(
            'INSERT INTO upload_queue (image_file, thumb_file, remote_filename, create_webpage, status) VALUES (:image_file, :thumb_file, :remote_filename, :create_webpage, \'pending\')'
        );
        $stmt->execute([
            ':image_file' => $imageFile,
            ':thumb_file' => $thumbFile,
            ':remote_filename' => $remoteFilename,
            ':create_webpage' => $createWebpage ? 1 : 0,
        ]);

        $id = (int) $this->db->lastInsertId();
        $this->logger->debug('Enqueued upload job', ['id' => $id, 'image' => $imageFile, 'remote' => $remoteFilename]);

        return $id;
    }

    public function getRemoteFilename(string $localFilename): ?string
    {
        $stmt = $this->db->prepare(
            'SELECT remote_filename FROM upload_queue WHERE image_file = :image_file ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':image_file' => $localFilename]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false || $row['remote_filename'] === '') {
            return null;
        }

        return (string) $row['remote_filename'];
    }

    /**
     * Fetch the next pending job whose backoff window has expired. Jobs with
     * next_attempt_at in the future are skipped so a flaky remote does not
     * get hammered with immediate retries — the markFailed path sets
     * next_attempt_at to 2^retries seconds (capped at 600s) in the future.
     *
     * @return array{id: int, image_file: string, thumb_file: string, remote_filename: string, create_webpage: int, status: string, retries: int, error_message: string|null, next_attempt_at?: string, created_at: string, updated_at: string}|null
     */
    public function fetchNext(): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM upload_queue WHERE status = \'pending\' AND next_attempt_at <= datetime(\'now\') ORDER BY id ASC LIMIT 1'
        );
        $stmt->execute();

        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        /** @var array{id: int, image_file: string, thumb_file: string, remote_filename: string, create_webpage: int, status: string, retries: int, error_message: string|null, next_attempt_at?: string, created_at: string, updated_at: string} $row */
        return $row;
    }

    /**
     * Atomically claim the next pending job: select-then-update in a single
     * IMMEDIATE transaction so concurrent workers cannot claim the same job.
     * The UPDATE's "AND status = 'pending'" guard means even if SQLite
     * serialization fails, the second writer's rowCount stays at 0 and we
     * report "no job claimed".
     *
     * Prefer this over fetchNext()+markInProgress() in worker loops.
     *
     * @return array{id: int, image_file: string, thumb_file: string, remote_filename: string, create_webpage: int, status: string, retries: int, error_message: string|null, created_at: string, updated_at: string}|null
     */
    public function claimNext(): ?array
    {
        $this->db->beginTransaction();
        try {
            $row = $this->fetchNext();
            if ($row === null) {
                $this->db->commit();
                return null;
            }

            $update = $this->db->prepare(
                'UPDATE upload_queue SET status = \'in_progress\', updated_at = datetime(\'now\') WHERE id = :id AND status = \'pending\''
            );
            $update->execute([':id' => (int) $row['id']]);

            if ($update->rowCount() === 0) {
                // Another worker won the race between SELECT and UPDATE.
                $this->db->commit();
                return null;
            }

            $row['status'] = 'in_progress';
            $this->db->commit();
            return $row;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    public function markInProgress(int $id): void
    {
        $stmt = $this->db->prepare(
            'UPDATE upload_queue SET status = \'in_progress\', updated_at = datetime(\'now\') WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
    }

    public function markCompleted(int $id): void
    {
        $stmt = $this->db->prepare(
            'UPDATE upload_queue SET status = \'completed\', updated_at = datetime(\'now\') WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
        $this->logger->debug('Upload completed', ['id' => $id]);
    }

    public function markFailed(int $id, string $errorMessage, int $maxRetries = 5): void
    {
        // Single-statement, atomic increment-and-decide: read retries from
        // the row itself in the same UPDATE, decide status via CASE, write
        // back. This eliminates the SELECT/UPDATE race that the old
        // two-step variant had when multiple writers (e.g. a stuck +
        // restarted worker) could both call markFailed on the same id.
        // The retries column update is also defensive backoff input — the
        // next_attempt_at is set in seconds = 2^retries (capped at 600s).
        $stmt = $this->db->prepare(
            'UPDATE upload_queue
                SET retries = retries + 1,
                    status = CASE WHEN retries + 1 >= :max THEN \'failed\' ELSE \'pending\' END,
                    error_message = :error,
                    next_attempt_at = datetime(\'now\', \'+\' || MIN(600, 1 << MIN(retries, 10)) || \' seconds\'),
                    updated_at = datetime(\'now\')
                WHERE id = :id'
        );
        $stmt->execute([
            ':id' => $id,
            ':max' => $maxRetries,
            ':error' => $errorMessage,
        ]);

        if ($stmt->rowCount() === 0) {
            return;
        }

        // Read back to log the resulting state.
        $check = $this->db->prepare('SELECT status, retries FROM upload_queue WHERE id = :id');
        $check->execute([':id' => $id]);
        $row = $check->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return;
        }

        $retries = (int) $row['retries'];
        if ($row['status'] === 'failed') {
            $this->logger->error('Upload permanently failed', ['id' => $id, 'retries' => $retries, 'error' => $errorMessage]);
        } else {
            $this->logger->debug('Upload failed, will retry', ['id' => $id, 'retries' => $retries, 'error' => $errorMessage]);
        }
    }

    public function resetStaleJobs(int $timeoutMinutes = 10): void
    {
        $stmt = $this->db->prepare(
            'UPDATE upload_queue SET status = \'pending\', updated_at = datetime(\'now\') WHERE status = \'in_progress\' AND updated_at < datetime(\'now\', :timeout)'
        );
        $stmt->execute([':timeout' => '-' . $timeoutMinutes . ' minutes']);
    }

    /**
     * Delete completed (and optionally permanently failed) jobs older than
     * the given retention window. Prevents the SQLite file from growing
     * unbounded over weeks of operation. Failed jobs are retained longer
     * so an operator can still inspect them after a problem.
     */
    public function purgeCompleted(int $retentionMinutes = 10080): int
    {
        $stmt = $this->db->prepare(
            'DELETE FROM upload_queue WHERE status = \'completed\' AND updated_at < datetime(\'now\', :retention)'
        );
        $stmt->execute([':retention' => '-' . $retentionMinutes . ' minutes']);
        $deleted = $stmt->rowCount();

        // Keep permanently failed jobs around 4x as long for forensics.
        $stmt = $this->db->prepare(
            'DELETE FROM upload_queue WHERE status = \'failed\' AND updated_at < datetime(\'now\', :retention)'
        );
        $stmt->execute([':retention' => '-' . ($retentionMinutes * 4) . ' minutes']);
        $deleted += $stmt->rowCount();

        if ($deleted > 0) {
            $this->logger->info('Purged old upload queue entries', ['count' => $deleted]);
        }

        return $deleted;
    }

    public function getPendingCount(): int
    {
        $stmt = $this->db->query('SELECT COUNT(*) FROM upload_queue WHERE status IN (\'pending\', \'in_progress\')');
        if ($stmt === false) {
            return 0;
        }

        return (int) $stmt->fetchColumn();
    }

    public function getFailedCount(): int
    {
        $stmt = $this->db->query('SELECT COUNT(*) FROM upload_queue WHERE status = \'failed\'');
        if ($stmt === false) {
            return 0;
        }

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array<int, array{id: int, image_file: string, status: string, retries: int, error_message: string|null}>
     */
    public function getStatus(): array
    {
        $stmt = $this->db->query('SELECT id, image_file, status, retries, error_message FROM upload_queue ORDER BY id ASC');
        if ($stmt === false) {
            return [];
        }

        /** @var array<int, array{id: int, image_file: string, status: string, retries: int, error_message: string|null}> */
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public static function getInstance(): self
    {
        if (!isset($GLOBALS[self::class])) {
            $GLOBALS[self::class] = new self();
        }

        return $GLOBALS[self::class];
    }
}
