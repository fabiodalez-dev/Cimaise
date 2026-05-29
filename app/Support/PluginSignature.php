<?php
declare(strict_types=1);

namespace App\Support;

/**
 * Ed25519 detached-signature verification for plugin archives (review finding H3).
 *
 * Trust model: a single vendor keypair. The PUBLIC key ships in the repo at
 * resources/keys/plugin-signing.pub (base64 of 32 raw bytes). The PRIVATE key
 * never lives in the repo — it is held by the maintainer / a GitHub Actions
 * secret and used only to sign release archives.
 *
 * At install time the raw ZIP bytes are verified against a detached signature
 * with `sodium_crypto_sign_verify_detached`. Only archives signed by the vendor
 * key install — this is a cryptographic allow-list that, unlike the regex
 * blocklist, cannot be bypassed by obfuscating the plugin source.
 *
 * Backward compatibility: when no public key is configured the feature is
 * "disabled" (isEnabled() === false) and the caller keeps its previous behaviour
 * (advisory static scan). Once a key is present, verification is enforced.
 */
final class PluginSignature
{
    /** Path to the base64-encoded Ed25519 public key shipped with the app. */
    public static function publicKeyPath(): string
    {
        return dirname(__DIR__, 2) . '/resources/keys/plugin-signing.pub';
    }

    /** True when a usable public key is configured (signature checks enforced). */
    public static function isEnabled(): bool
    {
        return self::publicKey() !== null;
    }

    /**
     * Raw 32-byte public key, or null when unconfigured/invalid.
     * Reads from the env override PLUGIN_SIGNING_PUBKEY first (base64), then the file.
     */
    public static function publicKey(): ?string
    {
        $b64 = getenv('PLUGIN_SIGNING_PUBKEY') ?: null;
        if ($b64 === null) {
            $path = self::publicKeyPath();
            if (!is_file($path)) {
                return null;
            }
            $b64 = trim((string) @file_get_contents($path));
        }
        if ($b64 === '') {
            return null;
        }
        $raw = base64_decode($b64, true);
        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return null;
        }
        return $raw;
    }

    /**
     * Verify a base64 detached signature over $message against the vendor key.
     * Returns false on any malformed input (never throws).
     */
    public static function verify(string $message, string $signatureB64): bool
    {
        $pub = self::publicKey();
        if ($pub === null) {
            return false;
        }
        $sig = base64_decode(trim($signatureB64), true);
        if ($sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }
        try {
            return sodium_crypto_sign_verify_detached($sig, $message, $pub);
        } catch (\SodiumException) {
            return false;
        }
    }

    /**
     * Sign $message with a base64 Ed25519 secret key (64 bytes) and return a
     * base64 detached signature. Used by the `plugin:sign` command / CI only.
     *
     * @throws \RuntimeException on an invalid key.
     */
    public static function sign(string $message, string $secretKeyB64): string
    {
        $secret = base64_decode(trim($secretKeyB64), true);
        if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \RuntimeException('Invalid Ed25519 secret key (expected base64 of '
                . SODIUM_CRYPTO_SIGN_SECRETKEYBYTES . ' bytes)');
        }
        return base64_encode(sodium_crypto_sign_detached($message, $secret));
    }

    /**
     * Generate a fresh Ed25519 keypair.
     *
     * @return array{public: string, secret: string} base64-encoded keys.
     */
    public static function generateKeypair(): array
    {
        $pair = sodium_crypto_sign_keypair();
        return [
            'public' => base64_encode(sodium_crypto_sign_publickey($pair)),
            'secret' => base64_encode(sodium_crypto_sign_secretkey($pair)),
        ];
    }
}
