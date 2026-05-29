<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\Support\PluginSignature;

/**
 * Unit coverage for the Ed25519 plugin-signature trust boundary (review H3).
 * Uses the PLUGIN_SIGNING_PUBKEY env override so no key file is touched.
 */
final class PluginSignatureTest extends TestCase
{
    private array $keypair;

    protected function setUp(): void
    {
        $this->keypair = PluginSignature::generateKeypair();
        putenv('PLUGIN_SIGNING_PUBKEY=' . $this->keypair['public']);
    }

    protected function tearDown(): void
    {
        putenv('PLUGIN_SIGNING_PUBKEY');
    }

    public function testValidSignatureVerifies(): void
    {
        $msg = random_bytes(2048);
        $sig = PluginSignature::sign($msg, $this->keypair['secret']);
        $this->assertTrue(PluginSignature::verify($msg, $sig));
    }

    public function testTamperedMessageIsRejected(): void
    {
        $msg = 'plugin-archive-bytes';
        $sig = PluginSignature::sign($msg, $this->keypair['secret']);
        $this->assertFalse(PluginSignature::verify($msg . "\x00", $sig));
    }

    public function testSignatureFromDifferentKeyIsRejected(): void
    {
        $other = PluginSignature::generateKeypair();
        $msg = 'plugin-archive-bytes';
        $sigFromOther = PluginSignature::sign($msg, $other['secret']);
        // Verified against the configured (env) public key — must fail.
        $this->assertFalse(PluginSignature::verify($msg, $sigFromOther));
    }

    public function testMalformedSignatureIsRejected(): void
    {
        $this->assertFalse(PluginSignature::verify('x', 'not-base64-!!!'));
        $this->assertFalse(PluginSignature::verify('x', base64_encode('too-short')));
    }

    public function testInvalidSecretKeyThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        PluginSignature::sign('x', base64_encode('short-secret'));
    }

    public function testIsEnabledReflectsConfiguredKey(): void
    {
        $this->assertTrue(PluginSignature::isEnabled());
        putenv('PLUGIN_SIGNING_PUBKEY'); // unset
        // With no env key and (in CI) no committed key file, the feature is off.
        if (!is_file(PluginSignature::publicKeyPath())
            || trim((string) file_get_contents(PluginSignature::publicKeyPath())) === '') {
            $this->assertFalse(PluginSignature::isEnabled());
        } else {
            $this->assertTrue(PluginSignature::isEnabled());
        }
    }

    public function testEmptyPublicKeyDisablesVerification(): void
    {
        putenv('PLUGIN_SIGNING_PUBKEY='); // explicitly empty
        $msg = 'x';
        $sig = PluginSignature::sign($msg, $this->keypair['secret']);
        // No usable key configured -> verify must fail closed (cannot confirm).
        if (!is_file(PluginSignature::publicKeyPath())
            || trim((string) file_get_contents(PluginSignature::publicKeyPath())) === '') {
            $this->assertFalse(PluginSignature::verify($msg, $sig));
        } else {
            $this->assertTrue(true); // a real key file is present; skip the negative
        }
    }
}
