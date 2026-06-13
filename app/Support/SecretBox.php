<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Symmetric encryption-at-rest for small secrets (the optional GitHub updater
 * token) stored in the settings table.
 *
 * libsodium authenticated encryption (XSalsa20-Poly1305 secretbox). The key is
 * derived from the application's SESSION_SECRET via a fixed-purpose generichash,
 * so no new env var is required. When SESSION_SECRET is unset or still the
 * placeholder, encryption is unavailable and callers fall back to env-only
 * configuration — never to plaintext-at-rest.
 *
 * Wire format: "ENC:" . base64( nonce(24) || ciphertext ). The "ENC:" prefix
 * lets readers distinguish an encrypted value from a legacy/plaintext one.
 */
final class SecretBox
{
    private const PREFIX = 'ENC:';
    private const KEY_CONTEXT = 'cimaise-secretbox-v1';

    /**
     * True when libsodium is present AND a usable SESSION_SECRET is configured.
     */
    public static function isAvailable(): bool
    {
        return function_exists('sodium_crypto_secretbox') && self::rawKey() !== null;
    }

    /**
     * Encrypt a plaintext secret. Returns "ENC:<base64>" or null when
     * encryption is unavailable (caller should then not persist the secret).
     */
    public static function encrypt(string $plaintext): ?string
    {
        $key = self::rawKey();
        if ($key === null || !function_exists('sodium_crypto_secretbox')) {
            return null;
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $key);
        $out = self::PREFIX . base64_encode($nonce . $cipher);
        sodium_memzero($key);
        return $out;
    }

    /**
     * Decrypt a value produced by encrypt(). Returns null on any failure
     * (missing key, malformed input, authentication failure) — never throws.
     */
    public static function decrypt(string $value): ?string
    {
        if (!self::isEncrypted($value) || !function_exists('sodium_crypto_secretbox_open')) {
            return null;
        }
        $key = self::rawKey();
        if ($key === null) {
            return null;
        }
        $raw = base64_decode(substr($value, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            sodium_memzero($key);
            return null;
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $key);
        sodium_memzero($key);
        return $plain === false ? null : $plain;
    }

    /**
     * Whether a stored value is in the encrypted wire format.
     */
    public static function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }

    /**
     * Derive the 32-byte secretbox key from SESSION_SECRET, or null when no
     * usable secret is configured.
     */
    private static function rawKey(): ?string
    {
        $secret = $_ENV['SESSION_SECRET'] ?? $_SERVER['SESSION_SECRET'] ?? getenv('SESSION_SECRET');
        if (!is_string($secret)) {
            return null;
        }
        $secret = trim($secret);
        // Reject empty / the shipped placeholder — deriving a key from a known
        // public value would be no protection at all.
        if ($secret === '' || $secret === 'your-session-secret-key-here') {
            return null;
        }
        if (!function_exists('sodium_crypto_generichash')) {
            return null;
        }
        return sodium_crypto_generichash(
            self::KEY_CONTEXT . $secret,
            '',
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES
        );
    }
}
