<?php

/** @var array $config */

require_once '../lib/boot.php';

use Photobooth\Service\GphotoConfigService;
use Photobooth\Service\LoggerService;

header('Content-Type: application/json');

$logger = LoggerService::getInstance()->getLogger('main');
$logger->debug(basename($_SERVER['PHP_SELF']));

if (!($config['camera_quicksettings']['enabled'] ?? false)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'camera_quicksettings disabled']);
    exit();
}

checkCsrfOrFail($_POST);

$auth = $_SESSION['camera_quicksettings_auth'] ?? null;
if (!is_array($auth) || ($auth['expires'] ?? 0) < time()) {
    unset($_SESSION['camera_quicksettings_auth']);
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'unauthenticated']);
    exit();
}

// extend session on successful interaction
$_SESSION['camera_quicksettings_auth']['expires'] = time() + 600;

$rawChanges = $_POST['changes'] ?? '[]';
$changes = json_decode(is_string($rawChanges) ? $rawChanges : '[]', true);
if (!is_array($changes) || $changes === []) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'no changes submitted']);
    exit();
}

$valid = [];
foreach ($changes as $entry) {
    if (!is_array($entry)) {
        continue;
    }
    $setting = (string) ($entry['setting'] ?? '');
    $key = (string) ($entry['key'] ?? '');
    $value = (string) ($entry['value'] ?? '');
    if (!in_array($setting, GphotoConfigService::SUPPORTED_SETTINGS, true)) {
        continue;
    }
    if ($key === '' || $value === '') {
        continue;
    }
    $valid[] = ['setting' => $setting, 'key' => $key, 'value' => $value];
}

if ($valid === []) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'no valid changes']);
    exit();
}

$pauseGo2rtc = (bool) ($config['camera_quicksettings']['pause_go2rtc'] ?? true);
$service = GphotoConfigService::getInstance();

$applied = [];
$errors = [];

$go2rtcUpdated = false;

try {
    $service->withCameraAccess($pauseGo2rtc, function (GphotoConfigService $svc) use ($valid, &$applied, &$errors, &$go2rtcUpdated) {
        foreach ($valid as $entry) {
            try {
                $svc->setValue($entry['key'], $entry['value']);
                $applied[] = [
                    'setting' => $entry['setting'],
                    'key' => $entry['key'],
                    'value' => $entry['value'],
                ];
            } catch (\Throwable $e) {
                $errors[] = [
                    'setting' => $entry['setting'],
                    'error' => $e->getMessage(),
                ];
            }
        }

        // Persist successful changes into go2rtc.yaml so the live-preview restart
        // (triggered automatically when we leave the withCameraAccess scope)
        // does not wipe them with the hardcoded values from the yaml.
        if ($applied !== []) {
            $kv = [];
            foreach ($applied as $entry) {
                $kv[$entry['key']] = $entry['value'];
            }
            $go2rtcUpdated = $svc->updateGo2rtcConfig($kv);
        }
    });
    $logger->debug('camera_quicksettings apply complete', [
        'applied_count' => count($applied),
        'errors_count' => count($errors),
        'go2rtc_updated' => $go2rtcUpdated,
    ]);
} catch (\Throwable $e) {
    $logger->error('camera_quicksettings apply failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'applied' => $applied,
        'errors' => $errors,
    ]);
    exit();
}

if ($errors !== []) {
    http_response_code(207);
}

echo json_encode([
    'success' => $errors === [],
    'applied' => $applied,
    'errors' => $errors,
    'go2rtc_updated' => $go2rtcUpdated,
]);
