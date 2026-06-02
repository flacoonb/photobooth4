<?php

require_once __DIR__ . '/../admin/admin_boot.php';

use Photobooth\Service\ConfigurationService;
use Photobooth\Service\LoggerService;
use Photobooth\Service\RemoteStorageService;

header('Content-Type: application/json');
checkCsrfOrFail($_POST);

$logger = LoggerService::getInstance()->getLogger('main');

$ftpData = $_POST['ftp'] ?? [];
if (!is_array($ftpData)) {
    echo json_encode(['response' => 'error', 'message' => 'ftp:no_connection', 'missing' => []]);
    exit();
}

$type = strtolower((string) ($ftpData['type'] ?? 'ftp'));
$host = trim((string) ($ftpData['baseURL'] ?? ''));
$port = (int) ($ftpData['port'] ?? 21);
$username = (string) ($ftpData['username'] ?? '');
$password = (string) ($ftpData['password'] ?? '');

if ($host === '' || $username === '') {
    echo json_encode(['response' => 'error', 'message' => 'ftp:no_connection', 'missing' => []]);
    exit();
}

if (!in_array($type, ['ftp', 'sftp'], true) || $port < 1 || $port > 65535) {
    echo json_encode(['response' => 'error', 'message' => 'ftp:no_connection', 'missing' => []]);
    exit();
}

// Password is never echoed back to the form; fall back to the saved value.
if ($password === '') {
    $savedConfig = ConfigurationService::getInstance()->getConfiguration();
    $password = (string) ($savedConfig['ftp']['password'] ?? '');
}

try {
    $filesystem = RemoteStorageService::createTemporaryFilesystem([
        'type' => $type,
        'baseURL' => $host,
        'port' => $port,
        'username' => $username,
        'password' => $password,
    ]);

    $count = 0;
    foreach ($filesystem->listContents('/', false) as $item) {
        $count++;
        $logger->debug('Test connection probe entry', ['path' => $item->path()]);
    }
    $logger->info('Test connection established.', ['entries' => $count]);

    echo json_encode(['response' => 'success', 'message' => 'ftp:connected', 'missing' => []]);
} catch (\Throwable $e) {
    $logger->error('Test connection failed.', [
        'type' => $type,
        'host' => $host,
        'port' => $port,
        'username' => $username,
        'error' => $e->getMessage(),
    ]);
    echo json_encode(['response' => 'error', 'message' => 'ftp:no_connection', 'missing' => []]);
}
exit();
