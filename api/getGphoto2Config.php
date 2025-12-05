<?php

/** @var array $config */

require_once '../lib/boot.php';

use Photobooth\Service\LoggerService;

header('Content-Type: application/json');

$logger = LoggerService::getInstance()->getLogger('main');
$logger->debug(basename($_SERVER['PHP_SELF']));

try {
    $setting = $_GET['setting'] ?? '';

    if (empty($setting) || !in_array($setting, ['aperture', 'iso'])) {
        throw new \Exception('Invalid setting parameter. Must be "aperture" or "iso".');
    }

    // Stop go2rtc service temporarily to access camera
    exec('sudo systemctl stop go2rtc.service 2>&1', $stopOutput, $stopCode);
    if ($stopCode !== 0) {
        $logger->warning('Failed to stop go2rtc service: ' . implode("\n", $stopOutput));
    }

    // Give camera time to release
    usleep(500000); // 0.5 seconds

    // Get camera configuration
    exec("gphoto2 --get-config $setting 2>&1", $output, $returnCode);

    // Restart go2rtc service
    exec('sudo systemctl start go2rtc.service 2>&1', $startOutput, $startCode);
    if ($startCode !== 0) {
        $logger->warning('Failed to start go2rtc service: ' . implode("\n", $startOutput));
    }

    if ($returnCode !== 0) {
        throw new \Exception('Failed to get camera configuration: ' . implode("\n", $output));
    }

    // Parse output
    $choices = [];
    $current = '';
    $label = '';

    foreach ($output as $line) {
        // Get label
        if (preg_match('/^Label:\s*(.+)$/', $line, $matches)) {
            $label = trim($matches[1]);
        }
        // Get current value
        if (preg_match('/^Current:\s*(.+)$/', $line, $matches)) {
            $current = trim($matches[1]);
        }
        // Get choices
        if (preg_match('/^Choice:\s*(\d+)\s+(.+)$/', $line, $matches)) {
            $index = $matches[1];
            $value = trim($matches[2]);
            $choices[] = [
                'index' => $index,
                'value' => $value,
                'label' => $value
            ];
        }
    }

    if (empty($choices)) {
        throw new \Exception('No choices found for ' . $setting . '. Camera might not support this setting or is not connected.');
    }

    echo json_encode([
        'success' => true,
        'setting' => $setting,
        'label' => $label,
        'current' => $current,
        'choices' => $choices
    ]);

} catch (\Throwable $e) {
    $logger->error($e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage()
    ]);
}
