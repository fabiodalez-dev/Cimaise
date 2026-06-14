<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\Support\SecretBox;

/**
 * Coverage for the at-rest secret box used to store the optional GitHub
 * updater token. Uses a real SESSION_SECRET via env override so no global
 * state leaks between tests.
 */
final class SecretBoxTest extends TestCase
{
    private ?string $prevSecret = null;

    protected function setUp(): void
    {
        $this->prevSecret = getenv('SESSION_SECRET') ?: null;
        putenv('SESSION_SECRET=unit-test-secret-0123456789abcdef');
        $_ENV['SESSION_SECRET'] = 'unit-test-secret-0123456789abcdef';
    }

    protected function tearDown(): void
    {
        if ($this->prevSecret === null) {
            putenv('SESSION_SECRET');
            unset($_ENV['SESSION_SECRET']);
        } else {
            putenv('SESSION_SECRET=' . $this->prevSecret);
            $_ENV['SESSION_SECRET'] = $this->prevSecret;
        }
    }

    public function testAvailableWhenSecretAndSodiumPresent(): void
    {
        if (!function_exists('sodium_crypto_secretbox')) {
            $this->markTestSkipped('libsodium not available');
        }
        $this->assertTrue(SecretBox::isAvailable());
    }

    public function testRoundTrip(): void
    {
        if (!SecretBox::isAvailable()) {
            $this->markTestSkipped('SecretBox unavailable');
        }
        $token = 'ghp_ExampleToken_1234567890';
        $enc = SecretBox::encrypt($token);
        $this->assertIsString($enc);
        $this->assertStringStartsWith('ENC:', $enc);
        $this->assertStringNotContainsString($token, $enc, 'plaintext must not appear in ciphertext');
        $this->assertSame($token, SecretBox::decrypt($enc));
    }

    public function testEachEncryptionUsesAFreshNonce(): void
    {
        if (!SecretBox::isAvailable()) {
            $this->markTestSkipped('SecretBox unavailable');
        }
        $a = SecretBox::encrypt('same-value');
        $b = SecretBox::encrypt('same-value');
        $this->assertNotSame($a, $b, 'random nonce should make ciphertexts differ');
        $this->assertSame('same-value', SecretBox::decrypt($a));
        $this->assertSame('same-value', SecretBox::decrypt($b));
    }

    public function testDecryptRejectsTamperedCiphertext(): void
    {
        if (!SecretBox::isAvailable()) {
            $this->markTestSkipped('SecretBox unavailable');
        }
        $enc = SecretBox::encrypt('secret');
        // Flip a character in the base64 body.
        $body = substr($enc, 4);
        $body[10] = ($body[10] === 'A') ? 'B' : 'A';
        $this->assertNull(SecretBox::decrypt('ENC:' . $body), 'auth tag must reject tampering');
    }

    public function testDecryptOfNonEncryptedValueIsNull(): void
    {
        $this->assertNull(SecretBox::decrypt('plain-text-not-encrypted'));
        $this->assertFalse(SecretBox::isEncrypted('plain'));
        $this->assertTrue(SecretBox::isEncrypted('ENC:whatever'));
    }

    public function testWrongKeyCannotDecrypt(): void
    {
        if (!SecretBox::isAvailable()) {
            $this->markTestSkipped('SecretBox unavailable');
        }
        $enc = SecretBox::encrypt('secret');
        // Rotate the secret → derived key changes → decrypt must fail.
        putenv('SESSION_SECRET=a-totally-different-secret-value');
        $_ENV['SESSION_SECRET'] = 'a-totally-different-secret-value';
        $this->assertNull(SecretBox::decrypt($enc));
    }

    public function testUnavailableWithPlaceholderSecret(): void
    {
        putenv('SESSION_SECRET=your-session-secret-key-here');
        $_ENV['SESSION_SECRET'] = 'your-session-secret-key-here';
        $this->assertFalse(SecretBox::isAvailable(), 'placeholder secret must not enable encryption');
        $this->assertNull(SecretBox::encrypt('x'));
    }

    public function testUnavailableWithEmptySecret(): void
    {
        putenv('SESSION_SECRET=');
        $_ENV['SESSION_SECRET'] = '';
        $this->assertFalse(SecretBox::isAvailable());
    }
}
