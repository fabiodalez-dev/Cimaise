<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use App\Support\Database;
use App\Support\PluginSignature;
use App\Support\Updater;

// Updater::debugLog() -> Logger uses the global envv() helper from
// app/Config/bootstrap.php, which the PHPUnit bootstrap does not load.
if (!function_exists('envv')) {
    function envv(string $key, $default = null)
    {
        $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        return ($v === false || $v === null) ? $default : $v;
    }
}

/**
 * Regression coverage for audit finding
 * Updater::downloadUpdate:same-origin-digest-no-publisher-signature.
 *
 * The sha256 the updater compares against comes from the same GitHub release
 * as the archive, so whoever can replace a release asset can replace its digest
 * too. The publisher signature (cimaise-vX.Y.Z.zip.sig, Ed25519 over the
 * "package" domain message) is what actually authenticates the package.
 *
 * Every case drives the REAL Updater::downloadUpdate() against a fake GitHub
 * API (PHP's built-in server on loopback), so the download, digest and
 * signature paths run exactly as in production.
 */
final class UpdaterPackageSignatureTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;
    private static string $serverDir = '';
    private static string $baseUrl = '';

    private Updater $updater;
    private string $root;
    /** @var array{public: string, secret: string} */
    private array $keypair;
    /** @var array<string, string|false> */
    private array $envBackup = [];

    public static function setUpBeforeClass(): void
    {
        if (!class_exists(ZipArchive::class) || !extension_loaded('curl')) {
            return;
        }
        self::$serverDir = sys_get_temp_dir() . '/cimaise_pkgsig_' . bin2hex(random_bytes(6));
        mkdir(self::$serverDir, 0775, true);
        file_put_contents(self::$serverDir . '/router.php', <<<'PHP'
<?php
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('#^/download/([A-Za-z0-9._-]+)$#', $path, $m) && is_file(__DIR__ . '/assets/' . $m[1])) {
    header('Content-Type: application/octet-stream');
    readfile(__DIR__ . '/assets/' . $m[1]);
} elseif (preg_match('#^/repos/[^/]+/[^/]+/releases/tags/v[0-9A-Za-z.-]+$#', $path) && is_file(__DIR__ . '/release.json')) {
    header('Content-Type: application/json');
    readfile(__DIR__ . '/release.json');
} else {
    http_response_code(404);
    echo '{"message":"Not Found"}';
}
PHP);

        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if ($socket === false) {
            return;
        }
        $port = (int) substr(strrchr((string) stream_socket_get_name($socket, false), ':') ?: ':0', 1);
        fclose($socket);

        // nosemgrep: php.lang.security.exec-use.exec-use -- fixed argv (no shell): PHP's built-in server on a loopback port, serving this fixture's temp directory.
        self::$server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, self::$serverDir . '/router.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            self::$serverDir
        );
        $deadline = microtime(true) + 10;
        do {
            $probe = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);
            usleep(50000);
        } while ($probe === false && microtime(true) < $deadline);
        if ($probe !== false) {
            fclose($probe);
            self::$baseUrl = 'http://127.0.0.1:' . $port;
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server, 9);
            proc_close(self::$server);
        }
        if (self::$serverDir !== '') {
            self::rrmdir(self::$serverDir);
        }
    }

    protected function setUp(): void
    {
        if (self::$baseUrl === '') {
            $this->markTestSkipped('ext-zip/ext-curl or a loopback port is unavailable');
        }

        foreach (['PLUGIN_SIGNING_PUBKEY', 'UPDATER_API_BASE', 'UPDATER_REPO', 'UPDATER_GITHUB_TOKEN'] as $k) {
            $this->envBackup[$k] = getenv($k);
        }
        $this->keypair = PluginSignature::generateKeypair();
        $this->setEnv('PLUGIN_SIGNING_PUBKEY', $this->keypair['public']);
        $this->setEnv('UPDATER_API_BASE', self::$baseUrl);
        $this->setEnv('UPDATER_REPO', 'test/cimaise');
        $this->setEnv('UPDATER_GITHUB_TOKEN', null);

        $this->root = sys_get_temp_dir() . '/cimaise_pkgsig_root_' . bin2hex(random_bytes(6));
        mkdir($this->root . '/storage/tmp', 0775, true);

        $ref = new ReflectionClass(Updater::class);
        $this->updater = $ref->newInstanceWithoutConstructor();
        foreach ([
            'rootPath' => $this->root,
            'tempPath' => $this->root . '/storage/tmp/cimaise_update_test',
            'db' => new Database(database: $this->root . '/test.sqlite', isSqlite: true),
        ] as $prop => $value) {
            (new ReflectionProperty(Updater::class, $prop))->setValue($this->updater, $value);
        }

        self::rrmdir(self::$serverDir . '/assets');
        mkdir(self::$serverDir . '/assets', 0775, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $k => $v) {
            $this->setEnv($k, $v === false ? null : $v);
        }
        if (isset($this->root)) {
            self::rrmdir($this->root);
        }
    }

    // ---- fixtures ------------------------------------------------------------

    private function setEnv(string $key, ?string $value): void
    {
        if ($value === null) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
            return;
        }
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    private function installedVersion(string $version): void
    {
        file_put_contents($this->root . '/version.json', json_encode(['version' => $version]));
    }

    /** Build cimaise-v<version>.zip in the fake release and return its bytes. */
    private function buildPackage(string $version): string
    {
        $name = 'cimaise-v' . $version;
        $file = self::$serverDir . '/assets/' . $name . '.zip';
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString($name . '/version.json', json_encode(['version' => $version]));
        $zip->addFromString($name . '/app/marker.txt', 'app');
        $zip->addFromString($name . '/public/index.php', '<?php');
        // Real packages are MBs; the updater rejects anything under 1000 bytes.
        $zip->addFromString($name . '/padding.bin', random_bytes(4096));
        $this->assertTrue($zip->close());
        return (string) file_get_contents($file);
    }

    /** Publish the release JSON: the package (with its CORRECT digest) and optionally a .sig. */
    private function publishRelease(string $version, string $packageBytes, ?string $signature): void
    {
        $name = 'cimaise-v' . $version . '.zip';
        $assets = [[
            'name' => $name,
            'size' => strlen($packageBytes),
            'digest' => 'sha256:' . hash('sha256', $packageBytes),
            'browser_download_url' => self::$baseUrl . '/download/' . $name,
        ]];
        if ($signature !== null) {
            file_put_contents(self::$serverDir . '/assets/' . $name . '.sig', $signature . "\n");
            $assets[] = [
                'name' => $name . '.sig',
                'size' => strlen($signature) + 1,
                'browser_download_url' => self::$baseUrl . '/download/' . $name . '.sig',
            ];
        }
        file_put_contents(self::$serverDir . '/release.json', json_encode([
            'tag_name' => 'v' . $version,
            'name' => 'Cimaise v' . $version,
            'draft' => false,
            'prerelease' => false,
            'assets' => $assets,
        ]));
    }

    /** @return array{success: bool, path: string|null, error: string|null} */
    private function download(string $version): array
    {
        return $this->updater->downloadUpdate($version);
    }

    private function assertRejected(array $result, string $needle): void
    {
        $this->assertFalse($result['success'], 'update must be refused');
        $this->assertNull($result['path']);
        $this->assertStringContainsString($needle, (string) $result['error']);
        // Refused BEFORE anything is written or extracted.
        $this->assertDirectoryDoesNotExist($this->root . '/storage/tmp/cimaise_update_test/extracted');
        $this->assertFileDoesNotExist($this->root . '/storage/tmp/cimaise_update_test/update.zip');
    }

    private static function signedVersion(): string
    {
        return Updater::PACKAGE_SIGNATURE_REQUIRED_FROM;
    }

    // ---- signed era: signature mandatory ---------------------------------------

    public function testUnsignedPackageWithMatchingDigestIsRejected(): void
    {
        $this->installedVersion('1.0.0');
        $version = self::signedVersion();
        $bytes = $this->buildPackage($version);
        $this->publishRelease($version, $bytes, null); // digest is correct, no .sig

        $this->assertRejected($this->download($version), 'no publisher signature');
    }

    public function testValidlySignedPackageIsAccepted(): void
    {
        $this->installedVersion('1.0.0');
        $version = self::signedVersion();
        $bytes = $this->buildPackage($version);
        $this->publishRelease($version, $bytes, PluginSignature::signPackage($version, hash('sha256', $bytes), $this->keypair['secret']));

        $result = $this->download($version);
        $this->assertTrue($result['success'], (string) $result['error']);
        $this->assertFileExists($result['path'] . '/version.json');
    }

    public function testPluginSignatureDoesNotValidateThePackage(): void
    {
        $this->installedVersion('1.0.0');
        $version = self::signedVersion();
        $bytes = $this->buildPackage($version);
        // e.g. produced by sign-plugin.yml for an asset uploaded to the release.
        $this->publishRelease($version, $bytes, PluginSignature::signPlugin($bytes, $this->keypair['secret']));

        $this->assertRejected($this->download($version), 'invalid publisher signature');
    }

    public function testSignatureForAnotherReleaseIsRejected(): void
    {
        $this->installedVersion('1.0.0');
        $version = self::signedVersion();
        $bytes = $this->buildPackage($version);
        $sigForOtherVersion = PluginSignature::signPackage('99.0.0', hash('sha256', $bytes), $this->keypair['secret']);
        $this->publishRelease($version, $bytes, $sigForOtherVersion);

        $this->assertRejected($this->download($version), 'invalid publisher signature');
    }

    public function testSignatureByAnotherKeyIsRejected(): void
    {
        $this->installedVersion('1.0.0');
        $version = self::signedVersion();
        $bytes = $this->buildPackage($version);
        $attacker = PluginSignature::generateKeypair();
        $this->publishRelease($version, $bytes, PluginSignature::signPackage($version, hash('sha256', $bytes), $attacker['secret']));

        $this->assertRejected($this->download($version), 'invalid publisher signature');
    }

    public function testMissingPublicKeyFailsClosed(): void
    {
        $this->installedVersion('1.0.0');
        $version = self::signedVersion();
        $bytes = $this->buildPackage($version);
        $this->publishRelease($version, $bytes, PluginSignature::signPackage($version, hash('sha256', $bytes), $this->keypair['secret']));
        // An unusable env key disables PluginSignature (no fallback to the file).
        $this->setEnv('PLUGIN_SIGNING_PUBKEY', 'not-a-key');
        $this->assertFalse(PluginSignature::isEnabled());

        $this->assertRejected($this->download($version), 'no publisher public key');
    }

    public function testSignedInstallRefusesUnsignedDowngrade(): void
    {
        // Ratchet: an install already in the signed era never accepts a
        // digest-only package again, even for an old (pre-signature) target.
        $this->installedVersion(self::signedVersion());
        $bytes = $this->buildPackage('1.0.0');
        $this->publishRelease('1.0.0', $bytes, null);

        $this->assertRejected($this->download('1.0.0'), 'no publisher signature');
    }

    // ---- staged rollout: pre-signature releases ------------------------------

    public function testLegacyTargetOnLegacyInstallInstallsOnDigest(): void
    {
        $this->installedVersion('0.9.0');
        $bytes = $this->buildPackage('1.0.0');
        $this->publishRelease('1.0.0', $bytes, null);

        $result = $this->download('1.0.0');
        $this->assertTrue($result['success'], (string) $result['error']);
    }

    public function testPublishedButInvalidSignatureIsNeverIgnored(): void
    {
        // Even on the legacy path, a .sig that IS published must verify.
        $this->installedVersion('0.9.0');
        $bytes = $this->buildPackage('1.0.0');
        $attacker = PluginSignature::generateKeypair();
        $this->publishRelease('1.0.0', $bytes, PluginSignature::signPackage('1.0.0', hash('sha256', $bytes), $attacker['secret']));

        $this->assertRejected($this->download('1.0.0'), 'invalid publisher signature');
    }

    public function testThresholdCountsPrereleasesOfTheFirstSignedVersion(): void
    {
        $required = new ReflectionMethod(Updater::class, 'packageSignatureRequired');
        $this->installedVersion('0.9.0');
        $this->assertTrue($required->invoke($this->updater, self::signedVersion() . '-rc.1'));
        $this->assertTrue($required->invoke($this->updater, 'v' . self::signedVersion()));
        $this->assertFalse($required->invoke($this->updater, '1.0.0'));
        // Unknown pre-release labels sort BELOW "-dev" in version_compare();
        // they must still count as the signed era.
        $this->assertTrue($required->invoke($this->updater, self::signedVersion() . '-test.1'));
        $this->assertTrue($required->invoke($this->updater, self::signedVersion() . '-alpha'));
        $this->assertTrue($required->invoke($this->updater, 'not-a-version'));
        $this->assertTrue($required->invoke($this->updater, '1.4.29junk'), 'invalid suffix on the target');
        $this->assertTrue($required->invoke($this->updater, '1.0.0+build'), 'build metadata is not accepted');
        $this->installedVersion('1.4.29junk');
        $this->assertTrue($required->invoke($this->updater, '1.0.0'), 'invalid suffix on the installed version');
        $this->installedVersion('0.9.0');
        $this->installedVersion(self::signedVersion() . '-test.1');
        $this->assertTrue($required->invoke($this->updater, '1.0.0'), 'installed prerelease keeps the ratchet');
    }

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $path = $dir . '/' . $f;
            // nosemgrep: php.lang.security.unlink-use.unlink-use -- test teardown over a self-created sys_get_temp_dir() fixture tree.
            is_dir($path) && !is_link($path) ? self::rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
