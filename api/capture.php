<?php

/** @var array $config */

require_once '../lib/boot.php';

use Photobooth\Image;
use Photobooth\Service\CaptureJobService;
use Photobooth\Service\CaptureRunService;
use Photobooth\Service\LoggerService;

header('Content-Type: application/json');

checkCsrfOrFail($_POST);

$logger = LoggerService::getInstance()->getLogger('main');
$logger->debug(basename($_SERVER['PHP_SELF']));

try {
    $style = (string)($_POST['style'] ?? '');

    $legacyAsyncEnabled = (bool)($config['dev']['capture_async_legacy'] ?? false);
    $isLegacyAsyncStyle = in_array($style, ['photo', 'custom'], true);
    $canUseLegacyAsync =
        $legacyAsyncEnabled &&
        $isLegacyAsyncStyle &&
        empty($config['dev']['demo_images']) &&
        !($config['preview']['mode'] === 'device_cam' && !empty($config['preview']['camTakesPic']));

    $logger->debug('capture legacy async decision', [
        'style' => $style,
        'legacyAsyncEnabled' => $legacyAsyncEnabled,
        'canUseLegacyAsync' => $canUseLegacyAsync,
    ]);

    if ($canUseLegacyAsync) {
        $asyncLogger = LoggerService::getInstance()->getLogger('captureasync');

        if (
            !empty($_POST['file']) &&
            (
                preg_match('/^[a-z0-9_]+\.jpg$/', (string)$_POST['file']) ||
                preg_match('/^[a-z0-9_]+\.mp4$/', (string)$_POST['file'])
            )
        ) {
            $file = (string)$_POST['file'];
        } else {
            $file = Image::createNewFilename($config['picture']['naming']);
            if ($config['database']['file'] != 'db') {
                $file = $config['database']['file'] . '_' . $file;
            }
        }

        $payload = $_POST;
        $payload['file'] = $file;

        $jobId = CaptureJobService::dispatch($payload);
        $asyncLogger->debug('capture legacy async job queued', [
            'job_id' => $jobId,
            'style' => $style,
            'file' => $file,
        ]);

        echo json_encode([
            'success' => 'image',
            'file' => $file,
            'async' => true,
            'job_id' => $jobId,
        ], JSON_INVALID_UTF8_SUBSTITUTE);
        exit();
    }

    // send image to frontend
    echo json_encode(CaptureRunService::run($_POST, $config), JSON_INVALID_UTF8_SUBSTITUTE);
    exit();
} catch (\Throwable $e) {
    $data = ['error' => $e->getMessage()];
    $logger->error($e->getMessage(), ['error' => $e->getMessage(), 'exception' => get_class($e)]);
    // JSON_INVALID_UTF8_SUBSTITUTE: capture command stderr (gphoto2, ffmpeg)
    // can include non-UTF-8 bytes; without substitution json_encode() returns
    // false and the browser receives an empty response.
    echo json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE);
    exit();
}
