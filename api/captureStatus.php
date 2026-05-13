<?php

/** @var array $config */

require_once '../lib/boot.php';

use Photobooth\Service\CaptureJobService;
use Photobooth\Service\LoggerService;

header('Content-Type: application/json');

checkCsrfOrFail($_POST);

$logger = LoggerService::getInstance()->getLogger('main');
$asyncLogger = LoggerService::getInstance()->getLogger('captureasync');

try {
    $jobId = (string) ($_POST['job_id'] ?? '');
    if (!CaptureJobService::isValidJobId($jobId)) {
        throw new \Exception('Invalid job id');
    }

    $job = CaptureJobService::read($jobId);
    if ($job === null) {
        throw new \Exception('Capture job not found');
    }

    $status = $job['status'] ?? 'unknown';

    $queuedStaleSeconds = (int)($config['dev']['capture_async_timeout_queued'] ?? 30);
    $runningStaleSeconds = (int)($config['dev']['capture_async_timeout_running'] ?? 120);
    $queuedStaleSeconds = max(5, min(600, $queuedStaleSeconds));
    $runningStaleSeconds = max(15, min(1800, $runningStaleSeconds));
    // Ensure "running" can never be shorter than "queued".
    if ($runningStaleSeconds < $queuedStaleSeconds) {
        $runningStaleSeconds = $queuedStaleSeconds;
    }

    // Stale thresholds are wall-clock maxima, not heartbeat windows: the
    // worker writes `updated_at` only on status transitions (queued→running,
    // running→done/failed), so we cannot distinguish a hung capture from a
    // legitimately slow one. The running threshold therefore has to be high
    // enough that the slowest realistic capture (gphoto2 with USB recovery
    // loops, ~60s worst case) still finishes in time, while still bounding
    // truly stuck workers.
    // Thresholds are configurable via:
    //   dev.capture_async_timeout_queued
    //   dev.capture_async_timeout_running
    if (in_array($status, ['queued', 'running'], true)) {
        $referenceTs = strtotime($job['updated_at'] ?? $job['created_at'] ?? '');
        $priorStatus = $status;
        $threshold = $priorStatus === 'queued' ? $queuedStaleSeconds : $runningStaleSeconds;
        if ($referenceTs !== false && (time() - $referenceTs) > $threshold) {
            // Re-read just before flipping to "failed" to narrow the race
            // window: a worker that finished while this request was in flight
            // may already have written status=done — we must not overwrite it.
            // This shrinks the TOCTOU window to the read↔write delta (µs) but
            // does not eliminate it entirely (would require flock).
            $fresh = CaptureJobService::read($jobId);
            if ($fresh !== null && in_array($fresh['status'] ?? '', ['queued', 'running'], true)) {
                $fresh['status'] = 'failed';
                $fresh['error'] = $priorStatus === 'queued'
                    ? 'Capture worker never started'
                    : 'Capture job timed out';
                $fresh['updated_at'] = date(DATE_ATOM);
                $fresh['completed_at'] = date(DATE_ATOM);
                CaptureJobService::write($fresh);
                $job = $fresh;
                $status = 'failed';
                $asyncLogger->error('capture async job stale/timed out', [
                    'job_id' => $jobId,
                    'prior_status' => $priorStatus,
                    'age_seconds' => time() - $referenceTs,
                    'threshold' => $threshold,
                ]);
            } else {
                // Worker beat us to the punch. Use the fresh state for the
                // response so the client sees the real outcome.
                if ($fresh !== null) {
                    $job = $fresh;
                    $status = (string) ($fresh['status'] ?? 'unknown');
                }
            }
        }
    }

    $response = [
        'job_id' => $jobId,
        'status' => $status,
    ];

    if ($status === 'done') {
        $response['result'] = $job['result'] ?? null;
    } elseif ($status === 'failed') {
        $response['error'] = $job['error'] ?? 'Capture failed';
        $asyncLogger->error('capture async job failed', [
            'job_id' => $jobId,
            'error' => $response['error'],
        ]);
    }

    echo json_encode($response, JSON_INVALID_UTF8_SUBSTITUTE);
    exit();
} catch (\Throwable $e) {
    $ctx = ['error' => $e->getMessage(), 'exception' => get_class($e)];
    $logger->error($e->getMessage(), $ctx);
    $asyncLogger->error($e->getMessage(), $ctx);
    http_response_code(500);
    echo json_encode([
        'status' => 'failed',
        'error' => $e->getMessage(),
    ], JSON_INVALID_UTF8_SUBSTITUTE);
    exit();
}
