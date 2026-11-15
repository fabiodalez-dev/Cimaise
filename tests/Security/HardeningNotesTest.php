<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Controllers\Admin\TextsController;
use App\Support\AppSecret;
use App\Support\CookieHelper;
use App\Support\ImageMetadataStripper;
use App\Support\PluginSignature;
use App\Support\SafeRedirect;
use App\Support\Updater;
use PHPUnit\Framework\TestCase;

/** Regressions for the hardening notes left open after the run-1 audit. */
final class HardeningNotesTest extends TestCase
{
    private const ENV = ['PLUGIN_SIGNING_PUBKEY', 'PLUGIN_ALLOW_UNSIGNED', 'UPDATER_API_BASE', 'TRUSTED_PROXIES', 'SESSION_SECRET'];

    /** @var array<string, mixed> */
    private array $server = [];
    private string $dir;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $this->dir = sys_get_temp_dir() . '/cimaise_hardening_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
        $this->clearEnv();
    }

    protected function tearDown(): void
    {
        $this->clearEnv();
        $_SERVER = $this->server;
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function clearEnv(): void
    {
        foreach (self::ENV as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }

    // ---- plugin signature fails closed ---------------------------------------

    public function testBrokenSigningKeyIsMisconfiguredAndNeverAllowsUnsignedPlugins(): void
    {
        putenv('PLUGIN_SIGNING_PUBKEY=not-a-key');
        putenv('PLUGIN_ALLOW_UNSIGNED=1');

        self::assertFalse(PluginSignature::isEnabled());
        self::assertTrue(PluginSignature::isMisconfigured());
        self::assertFalse(PluginSignature::unsignedPluginsAllowed());
    }

    public function testValidSigningKeyIsNeitherMisconfiguredNorBypassable(): void
    {
        $pair = PluginSignature::generateKeypair();
        putenv('PLUGIN_SIGNING_PUBKEY=' . $pair['public']);
        putenv('PLUGIN_ALLOW_UNSIGNED=1');

        self::assertTrue(PluginSignature::isEnabled());
        self::assertFalse(PluginSignature::isMisconfigured());
        self::assertFalse(PluginSignature::unsignedPluginsAllowed());
    }

    // ---- updater: https only, confined backup names --------------------------

    public function testUpdaterApiBaseRejectsPlainHttpExceptLoopback(): void
    {
        $default = $this->apiBase('');
        self::assertStringStartsWith('https://', $default);

        self::assertSame($default, $this->apiBase('http://attacker.example'));
        self::assertSame($default, $this->apiBase('ftp://mirror.example'));
        self::assertSame($default, $this->apiBase('http://localhost.attacker.example'));
        self::assertSame('https://ghe.example/api/v3', $this->apiBase('https://ghe.example/api/v3'));
        self::assertSame('http://127.0.0.1:8099', $this->apiBase('http://127.0.0.1:8099'));
        self::assertSame('http://localhost:8099', $this->apiBase('http://localhost:8099'));
    }

    private function apiBase(string $value): string
    {
        $value === '' ? putenv('UPDATER_API_BASE') : putenv('UPDATER_API_BASE=' . $value);
        $ref = new \ReflectionClass(Updater::class);
        $updater = $ref->newInstanceWithoutConstructor();
        $method = $ref->getMethod('apiBase');
        return rtrim((string) $method->invoke($updater), '/');
    }

    public function testBackupNamesAreASinglePlainSegment(): void
    {
        self::assertTrue(Updater::isValidBackupName('update_2026-11-15_101500'));
        foreach (['', '.', '..', '../x', 'a/b', 'a\\b', 'update_1.4', ".hidden", "x\0y", str_repeat('a', 200)] as $bad) {
            self::assertFalse(Updater::isValidBackupName($bad), var_export($bad, true));
        }
    }

    // ---- throttles that actually count ---------------------------------------

    /**
     * An outcome-based RateLimitMiddleware only records failed logins, wrong
     * album passwords and failed downloads: on any other GET it records
     * nothing, so every throttled public GET must count each request.
     */
    public function testPublicGetThrottlesCountEveryRequest(): void
    {
        $root = dirname(__DIR__, 2) . '/app/Config/';
        $checked = 0;
        foreach (['routes.php', 'media_routes.php'] as $file) {
            $src = (string) file_get_contents($root . $file);
            preg_match_all(
                '/\$app->get\(\'([^\']+)\'((?:(?!\$app->).)*?)\}\)->add\(new RateLimitMiddleware\(([^)]*)\)\)/s',
                $src,
                $m,
                PREG_SET_ORDER
            );
            foreach ($m as [$_, $route, $_body, $args]) {
                $checked++;
                if ($route === '/download/image/{id}') {
                    continue; // outcome-based on purpose: failed downloads count
                }
                self::assertMatchesRegularExpression('/,\s*true\s*$/', $args, "$file $route must count every request");
            }
        }
        self::assertGreaterThanOrEqual(8, $checked);
    }

    // ---- X-Forwarded-Proto only from trusted proxies -------------------------

    public function testForwardedProtoIsIgnoredFromAnUntrustedPeer(): void
    {
        unset($_SERVER['HTTPS']);
        $_SERVER['SERVER_PORT'] = 80;
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
        self::assertFalse(CookieHelper::isHttps());

        putenv('TRUSTED_PROXIES=172.16.0.0/12');
        self::assertFalse(CookieHelper::isHttps());

        $_SERVER['REMOTE_ADDR'] = '172.18.0.2';
        self::assertTrue(CookieHelper::isHttps());

        // Nearest hop decides: a client-supplied "https" in front does not.
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https, http';
        self::assertFalse(CookieHelper::isHttps());
    }

    public function testDockerVhostNoLongerMapsForwardedProtoToHttps(): void
    {
        $vhost = (string) file_get_contents(dirname(__DIR__, 2) . '/docker/apache-vhost.conf');
        self::assertDoesNotMatchRegularExpression('/^\s*SetEnvIf\s+X-Forwarded-Proto/mi', $vhost);
    }

    // ---- redirects -----------------------------------------------------------

    public function testRedirectTargetsStaySameOrigin(): void
    {
        $cases = [
            "/\\evil.example" => '/evil.example',
            "/\t/evil.example" => '/evil.example',
            "/\r\n/evil.example" => '/evil.example',
            '//evil.example' => '/evil.example',
            '\\\\evil.example' => '/evil.example',
            'https://evil.example' => '/',
            'javascript:alert(1)' => '/',
            '/admin/albums?page=2' => '/admin/albums?page=2',
            'admin' => '/admin',
        ];
        foreach ($cases as $in => $expected) {
            self::assertSame($expected, SafeRedirect::path($in), var_export($in, true));
        }
    }

    // ---- placeholder secret --------------------------------------------------

    public function testPlaceholderSessionSecretCountsAsNoSecret(): void
    {
        putenv('SESSION_SECRET=' . AppSecret::PLACEHOLDER);
        self::assertNull(AppSecret::session());
        putenv('SESSION_SECRET=  ');
        self::assertNull(AppSecret::session());
        putenv('SESSION_SECRET=a-real-random-secret-0123456789');
        self::assertSame('a-real-random-secret-0123456789', AppSecret::session());
    }

    // ---- translation codes ---------------------------------------------------

    public function testLanguageCodesThatSanitiseToNothingAreRejected(): void
    {
        self::assertNull(TextsController::sanitizeLanguageCode(''));
        self::assertNull(TextsController::sanitizeLanguageCode('../../'));
        self::assertNull(TextsController::sanitizeLanguageCode('...'));
        self::assertNull(TextsController::sanitizeLanguageCode('_admin'));
        self::assertSame('pt_BR', TextsController::sanitizeLanguageCode('pt_BR'));
        self::assertSame('en', TextsController::sanitizeLanguageCode('e/n'));
    }

    // ---- shipped php.ini -----------------------------------------------------

    public function testShippedPhpIniDoesNotDisplayErrors(): void
    {
        $ini = (string) file_get_contents(dirname(__DIR__, 2) . '/php.ini');
        self::assertMatchesRegularExpression('/^display_errors\s*=\s*Off\s*$/mi', $ini);
        self::assertDoesNotMatchRegularExpression('/^display_errors\s*=\s*On\b/mi', $ini);
    }

    // ---- side uploads lose their metadata ------------------------------------

    public function testJpegExifGpsIsStripped(): void
    {
        $path = $this->dir . '/logo.jpg';
        file_put_contents($path, $this->jpegWithExif('GPSLatitude-45.4642N'));
        self::assertStringContainsString('GPSLatitude-45.4642N', (string) file_get_contents($path));

        self::assertTrue(ImageMetadataStripper::stripInPlace($path));

        $bytes = (string) file_get_contents($path);
        self::assertStringNotContainsString('GPSLatitude-45.4642N', $bytes);
        self::assertStringNotContainsString("Exif\0\0", $bytes);
        self::assertNotFalse(@getimagesize($path), 'still a valid image');
    }

    public function testDecompressionBombHeaderIsRejectedBeforeDecode(): void
    {
        // A PNG whose IHDR claims 8000x6000 (48 MP) — over the 40 MP cap.
        $ihdr = pack('NNCCCCC', 8000, 6000, 8, 2, 0, 0, 0);
        $chunk = pack('N', strlen($ihdr)) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr));
        $path = $this->dir . '/bomb.png';
        file_put_contents($path, "\x89PNG\r\n\x1a\n" . $chunk);

        self::assertFalse(ImageMetadataStripper::withinPixelCap($path));
        self::assertFalse(ImageMetadataStripper::stripInPlace($path));
    }

    public function testNonImageIsRefused(): void
    {
        $path = $this->dir . '/x.jpg';
        file_put_contents($path, '<?php echo 1;');
        self::assertFalse(ImageMetadataStripper::stripInPlace($path));
    }

    /** A small GD JPEG with an APP1 Exif segment carrying $marker. */
    private function jpegWithExif(string $marker): string
    {
        $img = imagecreatetruecolor(16, 16);
        self::assertNotFalse($img);
        ob_start();
        imagejpeg($img, null, 90);
        $jpeg = (string) ob_get_clean();
        imagedestroy($img);

        // Minimal little-endian TIFF with one ImageDescription (0x010E) entry
        // pointing at $marker, wrapped as an Exif APP1 segment after SOI.
        $text = $marker . "\0";
        $tiff = "II*\0" . pack('V', 8)
            . pack('v', 1)
            . pack('vvVV', 0x010E, 2, strlen($text), 26)
            . pack('V', 0)
            . $text;
        $payload = "Exif\0\0" . $tiff;
        $app1 = "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;
        return substr($jpeg, 0, 2) . $app1 . substr($jpeg, 2);
    }
}
