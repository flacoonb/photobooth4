<?php

/** @var array $config */

require_once '../lib/boot.php';

use Photobooth\Service\LoggerService;

header('Content-Type: application/json');

$logger = LoggerService::getInstance()->getLogger('main');
$logger->debug(basename($_SERVER['PHP_SELF']));

/**
 * @return array<int, string>
 */
function getSettingAliases(string $setting): array
{
    if ($setting === 'aperture') {
        return [
            '/main/capturesettings/f-number',
            '/main/capturesettings/aperture',
            '/main/capturesettings/fnumber',
            '/main/settings/aperture',
            'f-number',
            'aperture',
        ];
    }

    if ($setting === 'iso') {
        return [
            '/main/imgsettings/iso',
            '/main/capturesettings/iso',
            '/main/settings/iso',
            'iso',
        ];
    }

    return [];
}

function normalizeConfigKey(string $key): string
{
    return strtolower((string) preg_replace('/[^a-z0-9]/', '', $key));
}

/**
 * @param array<int, string> $configKeys
 */
function resolveCameraConfigKey(string $setting, array $configKeys): ?string
{
    $aliases = getSettingAliases($setting);
    if ($aliases === []) {
        return null;
    }

    $normalizedAliases = array_map('normalizeConfigKey', $aliases);

    // Exact/alias match first.
    foreach ($configKeys as $configKey) {
        $normalizedKey = normalizeConfigKey($configKey);
        if (in_array($normalizedKey, $normalizedAliases, true)) {
            return $configKey;
        }
    }

    // Fallback heuristic based on basename and preferred sections.
    $bestKey = null;
    $bestScore = -1;
    foreach ($configKeys as $configKey) {
        $basename = basename($configKey);
        $normalizedBase = normalizeConfigKey($basename);
        $score = 0;

        foreach ($normalizedAliases as $alias) {
            if ($normalizedBase === $alias) {
                $score += 100;
                break;
            }
            if ($alias !== '' && str_contains($normalizedBase, $alias)) {
                $score += 40;
            }
        }

        if ($setting === 'aperture') {
            if (str_contains($configKey, '/capturesettings/')) {
                $score += 20;
            }
        }
        if ($setting === 'iso') {
            if (str_contains($configKey, '/imgsettings/')) {
                $score += 20;
            }
        }

        if ($score > $bestScore) {
            $bestScore = $score;
            $bestKey = $configKey;
        }
    }

    return $bestScore > 0 ? $bestKey : null;
}

/**
 * @return array{choices: array<int, array{index: string, value: string, label: string}>, current: string, label: string}
 */
function parseGphoto2ConfigOutput(array $output): array
{
    $choices = [];
    $current = '';
    $label = '';

    foreach ($output as $line) {
        if (preg_match('/^Label:\s*(.+)$/', $line, $matches)) {
            $label = trim($matches[1]);
        }
        if (preg_match('/^Current:\s*(.+)$/', $line, $matches)) {
            $current = trim($matches[1]);
        }
        if (preg_match('/^Choice:\s*(\d+)\s+(.+)$/', $line, $matches)) {
            $choices[] = [
                'index' => (string) $matches[1],
                'value' => trim($matches[2]),
                'label' => trim($matches[2]),
            ];
        }
    }

    return [
        'choices' => $choices,
        'current' => $current,
        'label' => $label,
    ];
}

try {
    $setting = $_GET['setting'] ?? '';

    if (empty($setting) || !in_array($setting, ['aperture', 'iso'])) {
        throw new \Exception('Invalid setting parameter. Must be "aperture" or "iso".');
    }

    $listOutput = [];
    $listReturnCode = 0;
    $resolvedKey = null;
    $output = [];
    $returnCode = 0;

    try {
        // Stop go2rtc service temporarily to access camera
        exec('sudo systemctl stop go2rtc.service 2>&1', $stopOutput, $stopCode);
        if ($stopCode !== 0) {
            $logger->warning('Failed to stop go2rtc service: ' . implode("\n", $stopOutput));
        }

        // Give camera time to release
        usleep(500000); // 0.5 seconds

        // Detect supported camera config keys first.
        exec('gphoto2 --list-config 2>&1', $listOutput, $listReturnCode);
        if ($listReturnCode !== 0) {
            throw new \Exception('Failed to list camera configuration: ' . implode("\n", $listOutput));
        }

        $configKeys = array_values(array_filter(array_map(
            static fn (string $line): string => trim($line),
            $listOutput
        ), static fn (string $line): bool => str_starts_with($line, '/')));

        $resolvedKey = resolveCameraConfigKey($setting, $configKeys) ?? $setting;

        // Read config of resolved key.
        exec('gphoto2 --get-config ' . escapeshellarg($resolvedKey) . ' 2>&1', $output, $returnCode);
    } finally {
        // Always restart service even if fetching config fails.
        exec('sudo systemctl start go2rtc.service 2>&1', $startOutput, $startCode);
        if ($startCode !== 0) {
            $logger->warning('Failed to start go2rtc service: ' . implode("\n", $startOutput));
        }
    }

    if ($returnCode !== 0) {
        throw new \Exception('Failed to get camera configuration for "' . $resolvedKey . '": ' . implode("\n", $output));
    }

    $parsed = parseGphoto2ConfigOutput($output);
    if (empty($parsed['choices'])) {
        throw new \Exception('No selectable choices found for "' . $resolvedKey . '". Camera may not expose this setting as choice list.');
    }

    echo json_encode([
        'success' => true,
        'setting' => $setting,
        'resolvedKey' => $resolvedKey,
        'label' => $parsed['label'],
        'current' => $parsed['current'],
        'choices' => $parsed['choices'],
    ]);
} catch (\Throwable $e) {
    $logger->error($e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage()
    ]);
}
