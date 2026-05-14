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

$auth = $_SESSION['camera_quicksettings_auth'] ?? null;
if (!is_array($auth) || ($auth['expires'] ?? 0) < time()) {
    unset($_SESSION['camera_quicksettings_auth']);
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'unauthenticated']);
    exit();
}

$pauseGo2rtc = (bool) ($config['camera_quicksettings']['pause_go2rtc'] ?? true);
$requested = $config['camera_quicksettings']['settings'] ?? GphotoConfigService::SUPPORTED_SETTINGS;
$requested = array_values(array_intersect($requested, GphotoConfigService::SUPPORTED_SETTINGS));

$service = GphotoConfigService::getInstance();

try {
    $result = $service->withCameraAccess($pauseGo2rtc, function (GphotoConfigService $svc) use ($requested) {
        $configKeys = $svc->listConfigKeys();
        $camera = $svc->getCameraInfo();

        $settings = [];
        foreach ($requested as $logical) {
            $resolvedKey = $svc->resolveKey($logical, $configKeys);
            if ($resolvedKey === null) {
                $settings[$logical] = [
                    'available' => false,
                    'error' => 'not supported by camera',
                ];
                continue;
            }

            try {
                $info = $svc->getChoices($resolvedKey);
                $settings[$logical] = [
                    'available' => !empty($info['choices']),
                    'key' => $info['key'],
                    'label' => $info['label'],
                    'current' => $info['current'],
                    'choices' => $info['choices'],
                ];
            } catch (\Throwable $e) {
                $settings[$logical] = [
                    'available' => false,
                    'key' => $resolvedKey,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return ['camera' => $camera, 'settings' => $settings];
    });

    echo json_encode([
        'success' => true,
        'camera' => $result['camera'],
        'settings' => $result['settings'],
    ]);
} catch (\Throwable $e) {
    $logger->error('camera_quicksettings discovery failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
