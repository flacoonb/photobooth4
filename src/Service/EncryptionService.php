<?php

declare(strict_types=1);

namespace Photobooth\Service;

use Photobooth\Utility\PathUtility;

class EncryptionService
{
    protected string $key;

    public function __construct()
    {
        $this->key = $this->loadOrCreateKey();
    }

    public function encrypt(string $plaintext): string
    {
        if ($plaintext === '' || $this->isEncrypted($plaintext)) {
            return $plaintext;
        }

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $this->key);

        return 'enc:' . base64_encode($nonce . $ciphertext);
    }

    public function decrypt(string $value): string
    {
        if (!$this->isEncrypted($value)) {
            return $value;
        }

        $decoded = base64_decode(substr($value, 4), true);
        if ($decoded === false || strlen($decoded) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return $value;
        }

        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);
        if ($plaintext === false) {
            return $value;
        }

        return $plaintext;
    }

    public function isEncrypted(string $value): bool
    {
        // The 'enc:' prefix is a hint, not proof — a plaintext password that
        // legitimately starts with "enc:" must still get encrypted, otherwise
        // it would land in storage as plaintext. We therefore additionally
        // require that the suffix is valid base64 of at least the minimum
        // ciphertext length (nonce + Poly1305 MAC) before treating it as
        // already-encrypted.
        if (!str_starts_with($value, 'enc:')) {
            return false;
        }
        $decoded = base64_decode(substr($value, 4), true);
        if ($decoded === false) {
            return false;
        }
        // SODIUM_CRYPTO_SECRETBOX_MACBYTES = 16, NONCEBYTES = 24.
        return strlen($decoded) >= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES;
    }

    protected function loadOrCreateKey(): string
    {
        $keyPath = PathUtility::getAbsolutePath('var/run/config_encryption_key');

        if (file_exists($keyPath)) {
            $key = file_get_contents($keyPath);
            if ($key !== false && strlen($key) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                return $key;
            }
        }

        $key = sodium_crypto_secretbox_keygen();
        $dir = dirname($keyPath);
        if (!is_dir($dir)) {
            // 0750 (was 0755): drop world-execute so anonymous local users
            // cannot stat the key file's existence. Group bit kept for
            // multi-user dev setups where the webserver group needs read
            // access to other var/run files (pid files, login throttle).
            mkdir($dir, 0750, true);
        }
        // Write atomically — temp file + rename — so a concurrent first-run
        // race cannot produce a half-written key file.
        $tmp = $keyPath . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $key) === false) {
            throw new \RuntimeException('Failed to write encryption key file');
        }
        chmod($tmp, 0600);
        if (!rename($tmp, $keyPath)) {
            @unlink($tmp);
            throw new \RuntimeException('Failed to finalize encryption key file');
        }

        return $key;
    }

    public static function getInstance(): self
    {
        if (!isset($GLOBALS[self::class])) {
            $GLOBALS[self::class] = new self();
        }

        return $GLOBALS[self::class];
    }
}
