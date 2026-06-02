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
    http_response_code(400);
    echo json_encode(['error' => 'Invalid ftp parameter']);
    exit();
}

$type = strtolower((string) ($ftpData['type'] ?? 'ftp'));
$host = trim((string) ($ftpData['baseURL'] ?? ''));
$port = (int) ($ftpData['port'] ?? 21);
$username = (string) ($ftpData['username'] ?? '');
$password = (string) ($ftpData['password'] ?? '');
$path = (string) ($_POST['path'] ?? '/');

// Input validation
if ($host === '' || $username === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing connection parameters']);
    exit();
}

if (!in_array($type, ['ftp', 'sftp'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Unsupported remote storage type']);
    exit();
}

if ($port < 1 || $port > 65535) {
    http_response_code(400);
    echo json_encode(['error' => 'Port out of range']);
    exit();
}

// Sanitize the browsed path: allow only an absolute POSIX-style path of
// "safe" characters. Reject anything that looks like a control sequence,
// command injection probe, or whitespace-only nonsense. Backslashes are
// not legitimate on FTP/SFTP servers and are commonly used in traversal
// attempts on misconfigured Windows servers.
if (strlen($path) > 512) {
    http_response_code(400);
    echo json_encode(['error' => 'Path too long']);
    exit();
}
if (preg_match('/[\x00-\x1F\x7F\\\\]/', $path) === 1) {
    http_response_code(400);
    echo json_encode(['error' => 'Path contains invalid characters']);
    exit();
}
if ($path === '') {
    $path = '/';
}

// Defense in depth: reject any "../" segment so the admin cannot try to
// escape their FTP home via this endpoint (proper FTP servers chroot the
// user anyway, but a misconfigured server should not be reachable this way
// from the photobooth admin panel).
$normalized = '/' . trim($path, '/');
$segments = array_filter(explode('/', $normalized), static fn (string $s) => $s !== '');
foreach ($segments as $segment) {
    if ($segment === '..' || $segment === '.') {
        http_response_code(400);
        echo json_encode(['error' => 'Path traversal not allowed']);
        exit();
    }
}
$path = '/' . implode('/', $segments);
if ($path === '/') {
    $path = '/';
}

// If password is empty, use the saved (decrypted) config password so the
// admin can test connection / browse folders without re-entering it.
if ($password === '') {
    $savedConfig = ConfigurationService::getInstance()->getConfiguration();
    $password = (string) ($savedConfig['ftp']['password'] ?? '');
}

try {
    $folders = RemoteStorageService::listFolders([
        'type' => $type,
        'baseURL' => $host,
        'port' => $port,
        'username' => $username,
        'password' => $password,
    ], $path);

    echo json_encode(['folders' => $folders]);
} catch (\Throwable $e) {
    // Don't echo the upstream stack trace to the admin UI — keep the
    // message concise and log details for diagnostics.
    $logger->error('ftpListFolders failed', [
        'type' => $type,
        'host' => $host,
        'port' => $port,
        'username' => $username,
        'path' => $path,
        'error' => $e->getMessage(),
        'exception' => get_class($e),
    ]);
    // Return a sanitized message — Flysystem exceptions may contain the host,
    // port, or credentials in the message text.
    $safe = preg_replace('/\b(?:\d{1,3}\.){3}\d{1,3}\b|:[0-9]{2,5}\b/', '[redacted]', $e->getMessage());
    $safe = mb_strimwidth($safe, 0, 200, '…');
    http_response_code(502);
    echo json_encode(['error' => $safe]);
}
exit();
