<?php

namespace Photobooth\Service;

class GphotoConfigService
{
    public const SUPPORTED_SETTINGS = ['iso', 'aperture', 'shutterspeed', 'whitebalance'];

    private const ALIASES = [
        'iso' => [
            '/main/imgsettings/iso',
            '/main/capturesettings/iso',
            '/main/settings/iso',
            'iso',
        ],
        'aperture' => [
            '/main/capturesettings/f-number',
            '/main/capturesettings/aperture',
            '/main/capturesettings/fnumber',
            '/main/settings/aperture',
            'f-number',
            'fnumber',
            'aperture',
        ],
        'shutterspeed' => [
            '/main/capturesettings/shutterspeed',
            '/main/capturesettings/shutterspeed2',
            '/main/capturesettings/eos-shutterspeed',
            '/main/settings/shutterspeed',
            'shutterspeed',
        ],
        'whitebalance' => [
            '/main/imgsettings/whitebalance',
            '/main/capturesettings/whitebalance',
            '/main/settings/whitebalance',
            'whitebalance',
            'wb',
        ],
    ];

    private const PREFERRED_SECTIONS = [
        'iso' => '/imgsettings/',
        'aperture' => '/capturesettings/',
        'shutterspeed' => '/capturesettings/',
        'whitebalance' => '/imgsettings/',
    ];

    private static ?self $instance = null;

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Run a gphoto2 command and return stdout/stderr lines + return code.
     *
     * @return array{0: array<int, string>, 1: int}
     */
    public function exec(string $args): array
    {
        $output = [];
        $returnCode = 0;
        exec('gphoto2 ' . $args . ' 2>&1', $output, $returnCode);
        return [$output, $returnCode];
    }

    /**
     * Stop the go2rtc service while running $callback so the camera USB endpoint
     * is released. Service is restarted in finally even if $callback throws.
     */
    public function withCameraAccess(bool $pauseGo2rtc, callable $callback): mixed
    {
        $logger = LoggerService::getInstance()->getLogger('main');
        $stopped = false;

        if ($pauseGo2rtc) {
            $stopOutput = [];
            $stopCode = 0;
            exec('sudo -n /usr/bin/systemctl stop go2rtc.service 2>&1', $stopOutput, $stopCode);
            if ($stopCode !== 0) {
                $logger->warning('GphotoConfigService: failed to stop go2rtc.service', [
                    'output' => $stopOutput,
                ]);
            } else {
                $stopped = true;
                // give libgphoto2 a moment to claim the freed USB endpoint
                usleep(500000);
            }
        }

        try {
            return $callback($this);
        } finally {
            if ($stopped) {
                $startOutput = [];
                $startCode = 0;
                exec('sudo -n /usr/bin/systemctl start go2rtc.service 2>&1', $startOutput, $startCode);
                if ($startCode !== 0) {
                    $logger->warning('GphotoConfigService: failed to restart go2rtc.service', [
                        'output' => $startOutput,
                    ]);
                }
            }
        }
    }

    /**
     * Camera vendor / model / serial via gphoto2 --summary (best effort).
     *
     * @return array{vendor: string, model: string, summary: string}
     */
    public function getCameraInfo(): array
    {
        [$out, $rc] = $this->exec('--auto-detect');
        $model = '';
        if ($rc === 0) {
            // first detected line after the header divider
            $started = false;
            foreach ($out as $line) {
                if (preg_match('/^-+\s+-+/', $line)) {
                    $started = true;
                    continue;
                }
                if ($started) {
                    $line = trim($line);
                    if ($line === '') {
                        continue;
                    }
                    // model is everything except the trailing port column
                    if (preg_match('/^(.+?)\s+(usb:[^\s]+)\s*$/', $line, $m)) {
                        $model = trim($m[1]);
                    } else {
                        $model = $line;
                    }
                    break;
                }
            }
        }

        $vendor = '';
        if ($model !== '') {
            $vendor = explode(' ', $model, 2)[0];
        }

        return [
            'vendor' => $vendor,
            'model' => $model,
            'summary' => implode("\n", $out),
        ];
    }

    /**
     * @return array<int, string> all gphoto2 config paths starting with "/"
     */
    public function listConfigKeys(): array
    {
        [$out, $rc] = $this->exec('--list-config');
        if ($rc !== 0) {
            throw new \RuntimeException('gphoto2 --list-config failed: ' . trim(implode("\n", $out)));
        }

        return array_values(array_filter(array_map('trim', $out), static fn(string $l): bool => str_starts_with($l, '/')));
    }

    /**
     * Resolve a logical setting (iso / aperture / shutterspeed / whitebalance)
     * to the actual gphoto2 config path supported by this camera.
     *
     * @param array<int, string> $configKeys
     */
    public function resolveKey(string $setting, array $configKeys): ?string
    {
        $aliases = self::ALIASES[$setting] ?? [];
        if ($aliases === []) {
            return null;
        }

        $normalize = static fn(string $k): string => strtolower((string) preg_replace('/[^a-z0-9]/', '', $k));
        $normalizedAliases = array_map($normalize, $aliases);

        foreach ($configKeys as $key) {
            if (in_array($normalize($key), $normalizedAliases, true)) {
                return $key;
            }
        }

        $bestKey = null;
        $bestScore = -1;
        $preferredSection = self::PREFERRED_SECTIONS[$setting] ?? null;

        foreach ($configKeys as $key) {
            $base = $normalize(basename($key));
            $score = 0;
            foreach ($normalizedAliases as $alias) {
                if ($alias === '') {
                    continue;
                }
                if ($base === $alias) {
                    $score += 100;
                    break;
                }
                if (str_contains($base, $alias)) {
                    $score += 40;
                }
            }
            if ($preferredSection !== null && str_contains($key, $preferredSection)) {
                $score += 20;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestKey = $key;
            }
        }

        return $bestScore > 0 ? $bestKey : null;
    }

    /**
     * Read a config node and parse its choice list.
     *
     * @return array{key: string, label: string, current: string, type: string, choices: array<int, array{index: string, value: string}>}
     */
    public function getChoices(string $key): array
    {
        [$out, $rc] = $this->exec('--get-config ' . escapeshellarg($key));
        if ($rc !== 0) {
            throw new \RuntimeException('gphoto2 --get-config ' . $key . ' failed: ' . trim(implode("\n", $out)));
        }

        $label = '';
        $current = '';
        $type = '';
        $choices = [];
        foreach ($out as $line) {
            if (preg_match('/^Label:\s*(.+)$/', $line, $m)) {
                $label = trim($m[1]);
            } elseif (preg_match('/^Type:\s*(.+)$/', $line, $m)) {
                $type = trim($m[1]);
            } elseif (preg_match('/^Current:\s*(.+)$/', $line, $m)) {
                $current = trim($m[1]);
            } elseif (preg_match('/^Choice:\s*(\d+)\s+(.+)$/', $line, $m)) {
                $choices[] = ['index' => (string) $m[1], 'value' => trim($m[2])];
            }
        }

        return [
            'key' => $key,
            'label' => $label,
            'current' => $current,
            'type' => $type,
            'choices' => $choices,
        ];
    }

    /**
     * Rewrite the `exec:gphoto2 ... --capture-movie ...` command in a go2rtc
     * YAML so it preserves the supplied key/value pairs across service
     * restarts. Without this, go2rtc would reset aperture/iso/… to whatever
     * the yaml says every time the live preview restarts.
     *
     * @param array<string, string> $keyValues  map of gphoto2 config key → value
     */
    public function updateGo2rtcConfig(array $keyValues, string $yamlPath = '/etc/go2rtc.yaml'): bool
    {
        if ($keyValues === [] || !is_file($yamlPath) || !is_writable($yamlPath)) {
            return false;
        }

        $content = file_get_contents($yamlPath);
        if ($content === false) {
            return false;
        }

        $lines = preg_split('/\R/', $content);
        if ($lines === false) {
            return false;
        }

        $changed = false;
        foreach ($lines as $idx => $line) {
            if (!str_contains($line, 'exec:gphoto2') || !str_contains($line, '--capture-movie')) {
                continue;
            }
            $newLine = $this->rewriteGphoto2Command($line, $keyValues);
            if ($newLine !== $line) {
                $lines[$idx] = $newLine;
                $changed = true;
            }
            break;
        }

        if ($changed) {
            // Direct write with LOCK_EX — `/etc` is typically not writable for
            // www-data so a tmp-file-then-rename strategy would fail. The yaml
            // itself is mode 0666 in this kiosk setup, which lets us write it
            // in place. The file is small (< 1 KB) so torn writes are unlikely.
            if (@file_put_contents($yamlPath, implode("\n", $lines), LOCK_EX) === false) {
                LoggerService::getInstance()->getLogger('main')->warning(
                    'GphotoConfigService: failed to write ' . $yamlPath,
                    ['error' => error_get_last()['message'] ?? 'unknown']
                );
                return false;
            }
        }

        return $changed;
    }

    /**
     * Replace or inject `--set-config <key>=<value>` arguments in a gphoto2
     * command line, matching by basename so we don't fight the existing yaml
     * convention (which usually uses bare names like "aperture").
     *
     * @param array<string, string> $keyValues
     */
    private function rewriteGphoto2Command(string $line, array $keyValues): string
    {
        foreach ($keyValues as $key => $value) {
            if (!preg_match('#^[a-zA-Z0-9_./:-]+$#', $key) || !preg_match('#^[A-Za-z0-9 _./:+,-]+$#', $value)) {
                continue;
            }

            $base = basename($key);
            $escBase = preg_quote($base, '#');
            $replacement = '--set-config ' . $key . '=' . $value;

            // Match either the bare basename (existing convention) or the full path
            $pattern = '#--set-config\s+(?:/\S*?/)?' . $escBase . '=\S+#';
            if (preg_match($pattern, $line)) {
                $replaced = preg_replace($pattern, $replacement, $line, 1);
            } else {
                // Inject right before --capture-movie
                $replaced = preg_replace('#(\s+--capture-movie\b)#', ' ' . $replacement . '$1', $line, 1);
            }
            if (is_string($replaced)) {
                $line = $replaced;
            }
        }
        return $line;
    }

    /**
     * Set a single config value. $value must be the choice value (string label),
     * gphoto2 accepts both index and value for RADIO/MENU types — we pass the
     * value verbatim so the frontend can stay agnostic.
     */
    public function setValue(string $key, string $value): void
    {
        if (!preg_match('#^[a-zA-Z0-9_./:-]+$#', $key)) {
            throw new \InvalidArgumentException('Invalid gphoto2 config key: ' . $key);
        }
        if (!preg_match('#^[A-Za-z0-9 _./:+,-]+$#', $value)) {
            throw new \InvalidArgumentException('Invalid gphoto2 config value: ' . $value);
        }

        [$out, $rc] = $this->exec('--set-config ' . escapeshellarg($key . '=' . $value));
        if ($rc !== 0) {
            $msg = '';
            foreach ($out as $line) {
                $line = trim($line);
                if ($line === '' || str_contains($line, 'For debugging') || str_contains($line, 'please run')) {
                    continue;
                }
                $msg = $line;
                break;
            }
            throw new \RuntimeException($msg !== '' ? $msg : 'gphoto2 --set-config failed for ' . $key);
        }
    }
}
