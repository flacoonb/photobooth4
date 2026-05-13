<?php

/** @var array $config */

require_once '../lib/boot.php';

use Photobooth\Service\CaptureJobService;
use Photobooth\Service\LoggerService;

header('Content-Type: application/json');

checkCsrfOrFail($_POST);

$logger = LoggerService::getInstance()->getLogger('main');
$asyncLogger = LoggerService::getInstance()->getLogger('captureasync');
$logger->debug(basename($_SERVER['PHP_SELF']));
$asyncLogger->debug(basename($_SERVER['PHP_SELF']));

try {
    if (!isset($_POST['style'])) {
        throw new \Exception('No style provided');
    }

    $jobId = CaptureJobService::dispatch($_POST);
    $asyncLogger->debug('capture async job queued', [
        'job_id' => $jobId,
        'style' => (string)($_POST['style'] ?? ''),
    ]);

    echo json_encode([
        'queued' => true,
        'job_id' => $jobId,
    ], JSON_INVALID_UTF8_SUBSTITUTE);
    exit();
} catch (\Throwable $e) {
    $ctx = ['error' => $e->getMessage(), 'exception' => get_class($e)];
    $logger->error($e->getMessage(), $ctx);
    $asyncLogger->error($e->getMessage(), $ctx);
    echo json_encode(['error' => $e->getMessage()], JSON_INVALID_UTF8_SUBSTITUTE);
    exit();
}
