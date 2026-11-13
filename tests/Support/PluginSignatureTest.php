<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\Support\PluginSignature;

/**
 * Unit coverage for the Ed25519 signature trust boundary (review H3) and its
 * domain separation: one vendor key signs plugins, release patches and the
 * updater package, and a signature for one purpose must never verify for
 * another. Uses the PLUGIN_SIGNING_PUBKEY env override so no key file is touched.
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

    public function testValidPluginSignatureVerifies(): void
    {
        $zip = random_bytes(2048);
        $sig = PluginSignature::signPlugin($zip, $this->keypair['secret']);
        $this->assertTrue(PluginSignature::verifyPlugin($zip, $sig));
    }

    public function testTamperedArchiveIsRejected(): void
    {
        $zip = 'plugin-archive-bytes';
        $sig = PluginSignature::signPlugin($zip, $this->keypair['secret']);
        $this->assertFalse(PluginSignature::verifyPlugin($zip . "\x00", $sig));
    }

    public function testSignatureFromDifferentKeyIsRejected(): void
    {
        $other = PluginSignature::generateKeypair();
        $zip = 'plugin-archive-bytes';
        $sigFromOther = PluginSignature::signPlugin($zip, $other['secret']);
        // Verified against the configured (env) public key — must fail.
        $this->assertFalse(PluginSignature::verifyPlugin($zip, $sigFromOther));
    }

    public function testMalformedSignatureIsRejected(): void
    {
        $this->assertFalse(PluginSignature::verifyPlugin('x', 'not-base64-!!!'));
        $this->assertFalse(PluginSignature::verifyPlugin('x', base64_encode('too-short')));
    }

    public function testInvalidSecretKeyThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        PluginSignature::signPlugin('x', base64_encode('short-secret'));
    }

    public function testRawByteSignatureIsNotAcceptedAsPluginSignature(): void
    {
        // A signature over the bare archive bytes (the pre-framing format, or
        // anything a generic "sign this file" oracle would produce) is refused.
        $zip = 'plugin-archive-bytes';
        $secret = base64_decode($this->keypair['secret'], true);
        $raw = base64_encode(sodium_crypto_sign_detached($zip, $secret));
        $this->assertFalse(PluginSignature::verifyPlugin($zip, $raw));
    }

    // ---- domain separation -------------------------------------------------

    public function testValidPackageSignatureVerifies(): void
    {
        $sha = hash('sha256', 'package-bytes');
        $sig = PluginSignature::signPackage('1.5.0', $sha, $this->keypair['secret']);
        $this->assertTrue(PluginSignature::verifyPackage('1.5.0', $sha, $sig));
        // "v" prefix and hex case are normalized, so the tag form verifies too.
        $this->assertTrue(PluginSignature::verifyPackage('v1.5.0', strtoupper($sha), $sig));
    }

    public function testPackageSignatureIsBoundToVersionAndHash(): void
    {
        $sha = hash('sha256', 'package-bytes');
        $sig = PluginSignature::signPackage('1.5.0', $sha, $this->keypair['secret']);
        $this->assertFalse(PluginSignature::verifyPackage('1.5.1', $sha, $sig));
        $this->assertFalse(PluginSignature::verifyPackage('1.5.0', hash('sha256', 'other-bytes'), $sig));
    }

    public function testPluginSignatureDoesNotValidateAPackage(): void
    {
        $zip = 'cimaise-v1.5.0.zip bytes';
        $pluginSig = PluginSignature::signPlugin($zip, $this->keypair['secret']);
        $this->assertTrue(PluginSignature::verifyPlugin($zip, $pluginSig));
        $this->assertFalse(PluginSignature::verifyPackage('1.5.0', hash('sha256', $zip), $pluginSig));
    }

    public function testPluginSignatureOverAForgedPackageMessageDoesNotValidateAPackage(): void
    {
        // Cross-protocol attempt: get the PLUGIN signer to sign bytes that equal
        // the package message. Framing puts the purpose inside the signed
        // message, so the result is still only a plugin signature.
        $sha = hash('sha256', 'malicious package');
        $forged = PluginSignature::packageMessage('1.5.0', $sha);
        $pluginSig = PluginSignature::signPlugin($forged, $this->keypair['secret']);
        $this->assertFalse(PluginSignature::verifyPackage('1.5.0', $sha, $pluginSig));
    }

    public function testPackageSignatureDoesNotValidateAPluginOrPatch(): void
    {
        $bytes = 'payload';
        $packageSig = PluginSignature::signPackage('1.5.0', hash('sha256', $bytes), $this->keypair['secret']);
        $this->assertFalse(PluginSignature::verifyPlugin($bytes, $packageSig));
        $this->assertFalse(PluginSignature::verifyPatch('post-install-patch.php', '1.5.0', $bytes, $packageSig));
    }

    public function testPatchSignatureIsBoundToNameAndRelease(): void
    {
        $php = "<?php return ['patches' => []];";
        $sig = PluginSignature::signPatch('post-install-patch.php', '1.5.0', $php, $this->keypair['secret']);
        $this->assertTrue(PluginSignature::verifyPatch('post-install-patch.php', '1.5.0', $php, $sig));
        $this->assertTrue(PluginSignature::verifyPatch('post-install-patch.php', 'v1.5.0', $php, $sig));
        // Replayed on another release, or as the other patch slot: refused.
        $this->assertFalse(PluginSignature::verifyPatch('post-install-patch.php', '1.6.0', $php, $sig));
        $this->assertFalse(PluginSignature::verifyPatch('pre-update-patch.php', '1.5.0', $php, $sig));
        // Nor is it a plugin or package signature.
        $this->assertFalse(PluginSignature::verifyPlugin($php, $sig));
        $this->assertFalse(PluginSignature::verifyPackage('1.5.0', hash('sha256', $php), $sig));
    }

    public function testMalformedPackageInputsFailClosed(): void
    {
        $sha = hash('sha256', 'x');
        $sig = PluginSignature::signPackage('1.5.0', $sha, $this->keypair['secret']);
        $this->assertFalse(PluginSignature::verifyPackage('not-a-version', $sha, $sig));
        $this->assertFalse(PluginSignature::verifyPackage("1.5.0\x00", $sha, $sig));
        $this->assertFalse(PluginSignature::verifyPackage('1.5.0', 'abc', $sig));
        $this->assertFalse(PluginSignature::verifyPatch("../evil.php", '1.5.0', 'x', $sig));
    }

    public function testMessagesAreFramedPerPurpose(): void
    {
        $sha = hash('sha256', 'x');
        $this->assertSame("cimaise-signature-v1\0package\0" . '1.5.0' . "\0" . $sha, PluginSignature::packageMessage('v1.5.0', $sha));
        $this->assertSame("cimaise-signature-v1\0plugin\0" . $sha, PluginSignature::pluginMessage('x'));
        $this->assertSame(
            "cimaise-signature-v1\0patch\0pre-update-patch.php\0" . '1.5.0-rc.1' . "\0" . $sha,
            PluginSignature::patchMessage('pre-update-patch.php', '1.5.0-rc.1', 'x')
        );
    }

    // ---- key configuration -------------------------------------------------

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
        putenv('PLUGIN_SIGNING_PUBKEY='); // explicitly empty -> fall back to the file key (if any)
        $zip = 'x';
        $sig = PluginSignature::signPlugin($zip, $this->keypair['secret']);
        // Either no usable key is configured (verify fails closed) OR a committed
        // file key is present — but that key did NOT sign $sig (we used the test
        // keypair), so verification must fail in both cases. Always assertFalse,
        // so the test never degrades into a no-op.
        $this->assertFalse(PluginSignature::verifyPlugin($zip, $sig));
    }
}
