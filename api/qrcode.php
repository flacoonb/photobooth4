<?php

/** @var array $config */

use Photobooth\Service\LoggerService;
use Photobooth\Service\RemoteStorageService;
use Photobooth\Service\UploadQueueService;
use Photobooth\Utility\PathUtility;
use Photobooth\Utility\QrCodeUtility;

require_once '../lib/boot.php';

$filename = (isset($_GET['filename']) && $_GET['filename']) != '' ? $_GET['filename'] : false;
if ($filename) {
    $filename = basename((string)$filename);
    if ($filename === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $filename)) {
        http_response_code(400);
        echo 'Invalid filename.';
        exit();
    }
    // Decide whether the QR should point to the remote gallery or the local
    // photobooth: only switch to remote when the image actually has a remote
    // mapping in the queue, otherwise the remote URL would 404 (the queue
    // mapping is the only source of truth for the random remote filename).
    $url = $config['qr']['url'];
    $useRemote = false;
    $remoteFilename = $filename;
    if ($config['ftp']['enabled'] && $config['ftp']['useForQr']) {
        try {
            $uploadQueue = UploadQueueService::getInstance();
            $mapped = $uploadQueue->getRemoteFilename($filename);
            if ($mapped !== null && $mapped !== '') {
                $remoteStorageService = RemoteStorageService::getInstance();
                $url = $remoteStorageService->getWebpageUri();
                $remoteFilename = $mapped;
                $useRemote = true;
            } else {
                LoggerService::getInstance()->getLogger('uploadqueue')->warning(
                    'QR falling back to local URL — no remote mapping for image',
                    ['image' => $filename]
                );
            }
        } catch (\Throwable $queueError) {
            LoggerService::getInstance()->getLogger('uploadqueue')->error(
                'Upload queue unavailable for QR lookup; falling back to local URL',
                ['image' => $filename, 'error' => $queueError->getMessage()]
            );
        }
    }
    if ($config['qr']['append_filename']) {
        if ($useRemote) {
            $url .= '/?img=' . rawurlencode($remoteFilename);
        } else {
            $url .= $filename;
        }
    }
    $url = PathUtility::getPublicPath($url, true);
    try {
        $result = QrCodeUtility::create($url);
        header('Content-Type: ' . $result->getMimeType());
        echo $result->getString();
    } catch (\Exception $e) {
        http_response_code(500);
        echo 'Error generating QR Code.';
        if ($config['dev']['loglevel'] > 1) {
            echo $e->getMessage();
        }
    }

} else {
    http_response_code(400);
    echo 'No filename defined.';
}
exit();
