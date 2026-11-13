<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Ed25519 detached signatures for everything the project's vendor key vouches
 * for: plugin archives (review finding H3), signed migration patches and the
 * self-updater's release package.
 *
 * Trust model: a single vendor keypair. The PUBLIC key ships in the repo at
 * resources/keys/plugin-signing.pub (base64 of 32 raw bytes). The PRIVATE key
 * never lives in the repo — it is held by the maintainer / a GitHub Actions
 * secret and used only to sign release artifacts.
 *
 * DOMAIN SEPARATION: one key signs three kinds of artifact, so a signature made
 * for one purpose must never be accepted for another (e.g. a plugin signature
 * replayed as a release-package signature, or a patch signed for 1.5.0 replayed
 * on 1.6.0). Nothing is ever signed as raw bytes: every signature covers a
 * structured message
 *
 *     "cimaise-signature-v1" \0 <purpose> \0 <field> \0 <field> ...
 *
 *   plugin  : sha256(archive)
 *   patch   : asset name, release version, sha256(patch file)
 *   package : release version, sha256(package zip)
 *
 * Fields are NUL-separated and may not contain NUL, and every purpose has a
 * fixed field count, so two different (purpose, fields) tuples can never
 * serialize to the same message. Only the typed sign*() / verify*() helpers are
 * public; the raw primitives are private so no caller can verify a signature
 * over unframed bytes.
 *
 * Backward compatibility: when no public key is configured the feature is
 * "disabled" (isEnabled() === false). Callers decide what that means: plugin
 * upload keeps the advisory static scan, patches are skipped, and the updater
 * refuses any release that requires a package signature.
 */
final class PluginSignature
{
    public const PURPOSE_PLUGIN = 'plugin';
    public const PURPOSE_PATCH = 'patch';
    public const PURPOSE_PACKAGE = 'package';

    /** Version tag of the message framing; bump only together with every signer. */
    private const CONTEXT = 'cimaise-signature-v1';

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

    // ---- message framing -----------------------------------------------------

    /** Signed message for a plugin archive. */
    public static function pluginMessage(string $archiveBytes): string
    {
        return self::frame(self::PURPOSE_PLUGIN, [hash('sha256', $archiveBytes)]);
    }

    /**
     * Signed message for a release migration patch, bound to the asset name and
     * the release it belongs to so it cannot be replayed under another name or
     * on another release.
     *
     * @throws \InvalidArgumentException on a malformed asset name or version.
     */
    public static function patchMessage(string $assetName, string $version, string $content): string
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\z/', $assetName) !== 1) {
            throw new \InvalidArgumentException('Invalid patch asset name');
        }
        return self::frame(self::PURPOSE_PATCH, [
            $assetName,
            self::normalizeVersion($version),
            hash('sha256', $content),
        ]);
    }

    /**
     * Signed message for the self-updater's release package.
     *
     * @throws \InvalidArgumentException on a malformed version or sha256.
     */
    public static function packageMessage(string $version, string $sha256Hex): string
    {
        $sha = strtolower(trim($sha256Hex, " \t\n\r"));
        if (preg_match('/^[a-f0-9]{64}\z/', $sha) !== 1) {
            throw new \InvalidArgumentException('Invalid package sha256');
        }
        return self::frame(self::PURPOSE_PACKAGE, [self::normalizeVersion($version), $sha]);
    }

    /**
     * "v1.5.0" / "1.5.0" -> "1.5.0" (pre-release suffixes kept: "1.5.0-rc.1").
     *
     * @throws \InvalidArgumentException when the string is not a release version.
     */
    public static function normalizeVersion(string $version): string
    {
        // Whitespace only: trim()'s default set also strips NUL, which must stay invalid.
        $v = ltrim(trim($version, " \t\n\r"), 'v');
        if (preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?\z/', $v) !== 1) {
            throw new \InvalidArgumentException('Invalid release version: ' . $version);
        }
        return $v;
    }

    /** @param list<string> $fields */
    private static function frame(string $purpose, array $fields): string
    {
        foreach ($fields as $field) {
            if ($field === '' || str_contains($field, "\0")) {
                throw new \InvalidArgumentException('Signature message fields must be non-empty and NUL-free');
            }
        }
        return implode("\0", [self::CONTEXT, $purpose, ...$fields]);
    }

    // ---- typed sign / verify ------------------------------------------------

    public static function signPlugin(string $archiveBytes, string $secretKeyB64): string
    {
        return self::signMessage(self::pluginMessage($archiveBytes), $secretKeyB64);
    }

    public static function verifyPlugin(string $archiveBytes, string $signatureB64): bool
    {
        return self::verifyMessage(self::pluginMessage($archiveBytes), $signatureB64);
    }

    public static function signPatch(string $assetName, string $version, string $content, string $secretKeyB64): string
    {
        return self::signMessage(self::patchMessage($assetName, $version, $content), $secretKeyB64);
    }

    public static function verifyPatch(string $assetName, string $version, string $content, string $signatureB64): bool
    {
        try {
            $message = self::patchMessage($assetName, $version, $content);
        } catch (\InvalidArgumentException) {
            return false;
        }
        return self::verifyMessage($message, $signatureB64);
    }

    public static function signPackage(string $version, string $sha256Hex, string $secretKeyB64): string
    {
        return self::signMessage(self::packageMessage($version, $sha256Hex), $secretKeyB64);
    }

    public static function verifyPackage(string $version, string $sha256Hex, string $signatureB64): bool
    {
        try {
            $message = self::packageMessage($version, $sha256Hex);
        } catch (\InvalidArgumentException) {
            return false;
        }
        return self::verifyMessage($message, $signatureB64);
    }

    // ---- primitives (private: never expose verification of unframed bytes) ---

    /**
     * Verify a base64 detached signature over a framed message against the
     * vendor key. Returns false on any malformed input (never throws).
     */
    private static function verifyMessage(string $message, string $signatureB64): bool
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
     * Sign a framed message with a base64 Ed25519 secret key (64 bytes) and
     * return a base64 detached signature.
     *
     * @throws \RuntimeException on an invalid key.
     */
    private static function signMessage(string $message, string $secretKeyB64): string
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
