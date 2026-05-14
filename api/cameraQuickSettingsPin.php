<?php

/** @var array $config */

require_once '../lib/boot.php';

use Photobooth\Service\LoggerService;
use Photobooth\Utility\AdminKeypad;
use Photobooth\Utility\PathUtility;

header('Content-Type: application/json');

$logger = LoggerService::getInstance()->getLogger('main');
$logger->debug(basename($_SERVER['PHP_SELF']));

if (!($config['camera_quicksettings']['enabled'] ?? false)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'camera_quicksettings disabled']);
    exit();
}

checkCsrfOrFail($_POST);

$storedPin = $config['camera_quicksettings']['pin'] ?? null;
if (!is_string($storedPin) || $storedPin === '') {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'pin not configured']);
    exit();
}

$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$now = time();
$throttleFile = PathUtility::getAbsolutePath('var/run/camera_quicksettings_throttle.json');
$windowSeconds = 300;
$maxAttempts = 10;

$ipAttempts = ['count' => 0, 'window' => $now];
if (is_readable($throttleFile)) {
    $raw = file_get_contents($throttleFile);
    $decoded = json_decode((string) $raw, true);
    if (is_array($decoded) && isset($decoded[$ip]) && is_array($decoded[$ip])) {
        $ipAttempts = $decoded[$ip];
    }
}
if (($now - ($ipAttempts['window'] ?? 0)) > $windowSeconds) {
    $ipAttempts = ['count' => 0, 'window' => $now];
}

$sessionAttempts = $_SESSION['camera_quicksettings_attempts'] ?? ['count' => 0, 'window' => $now];
if (($now - ($sessionAttempts['window'] ?? 0)) > $windowSeconds) {
    $sessionAttempts = ['count' => 0, 'window' => $now];
}

if ($sessionAttempts['count'] >= $maxAttempts || $ipAttempts['count'] >= $maxAttempts) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'too many attempts']);
    exit();
}

$entered = (string) ($_POST['pin'] ?? '');
$ok = false;
if ($entered !== '') {
    if (AdminKeypad::isHashedPin($storedPin)) {
        $ok = password_verify($entered, $storedPin);
    } else {
        $ok = hash_equals($storedPin, $entered);
    }
}

if (!$ok) {
    $sessionAttempts['count']++;
    $ipAttempts['count']++;
    $_SESSION['camera_quicksettings_attempts'] = $sessionAttempts;
    usleep(300000);

    $persisted = [];
    if (is_readable($throttleFile)) {
        $raw = file_get_contents($throttleFile);
        $decoded = json_decode((string) $raw, true);
        if (is_array($decoded)) {
            $persisted = $decoded;
        }
    }
    $persisted[$ip] = $ipAttempts;
    @file_put_contents($throttleFile, json_encode($persisted), LOCK_EX);

    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'invalid pin']);
    exit();
}

session_regenerate_id(true);
$_SESSION['camera_quicksettings_auth'] = [
    'expires' => $now + 600,
];
$_SESSION['camera_quicksettings_attempts'] = ['count' => 0, 'window' => $now];

$persisted = [];
if (is_readable($throttleFile)) {
    $raw = file_get_contents($throttleFile);
    $decoded = json_decode((string) $raw, true);
    if (is_array($decoded)) {
        $persisted = $decoded;
    }
}
unset($persisted[$ip]);
@file_put_contents($throttleFile, json_encode($persisted), LOCK_EX);

echo json_encode([
    'success' => true,
    'csrf' => $_SESSION['csrf'] ?? '',
]);
