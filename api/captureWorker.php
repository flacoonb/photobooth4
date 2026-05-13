<?php

/** @var array $config */

// Refuse to run anywhere but the CLI. This script is intended to be invoked
// by CaptureJobService::dispatch() as a background CLI process; serving it
// over HTTP would needlessly run the full bootstrap (sessions, autoload,
// config) for an instantly-exiting request and is information disclosure.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../lib/boot.php';

use Photobooth\Service\CaptureJobService;
use Photobooth\Service\CaptureRunService;
use Photobooth\Service\LoggerService;

if (!isset($argv[1])) {
    exit(1);
}

$jobId = (string) $argv[1];
if (!CaptureJobService::isValidJobId($jobId)) {
    exit(1);
}

$logger = LoggerService::getInstance()->getLogger('main');
$asyncLogger = LoggerService::getInstance()->getLogger('captureasync');
$job = CaptureJobService::read($jobId);
if ($job === null) {
    $logger->error('captureWorker: job not found', ['job_id' => $jobId]);
    $asyncLogger->error('capture worker job not found', ['job_id' => $jobId]);
    exit(1);
}

try {
    $payload = $job['payload'] ?? null;
    if (!is_array($payload)) {
        throw new \RuntimeException('Invalid job payload');
    }

    $job['status'] = 'running';
    $job['started_at'] = date(DATE_ATOM);
    $job['updated_at'] = date(DATE_ATOM);
    CaptureJobService::write($job);
    $asyncLogger->debug('capture worker started', ['job_id' => $jobId]);

    $result = CaptureRunService::run($payload, $config);

    $job['status'] = 'done';
    $job['result'] = $result;
    $job['updated_at'] = date(DATE_ATOM);
    $job['completed_at'] = date(DATE_ATOM);
    CaptureJobService::write($job);
    $asyncLogger->debug('capture worker completed', [
        'job_id' => $jobId,
        'file' => (string)($result['file'] ?? ''),
    ]);

    exit(0);
} catch (\Throwable $e) {
    try {
        $job['status'] = 'failed';
        $job['error'] = $e->getMessage();
        $job['updated_at'] = date(DATE_ATOM);
        $job['completed_at'] = date(DATE_ATOM);
        CaptureJobService::write($job);
    } catch (\Throwable $writeError) {
        $logger->error('captureWorker could not persist failure status', [
            'job_id' => $jobId,
            'error' => $writeError->getMessage(),
        ]);
    }

    $logger->error('captureWorker failed', [
        'job_id' => $jobId,
        'error' => $e->getMessage(),
        'exception' => get_class($e),
    ]);
    $asyncLogger->error('capture worker failed', [
        'job_id' => $jobId,
        'error' => $e->getMessage(),
        'exception' => get_class($e),
    ]);

    exit(1);
} finally {
    CaptureJobService::cleanup();
}
