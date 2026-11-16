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

    public function testSigningKeyFromDotenvOnlyIsHonoured(): void
    {
        // Dotenv's immutable loader fills $_ENV/$_SERVER but not getenv().
        $pair = PluginSignature::generateKeypair();
        $_ENV['PLUGIN_SIGNING_PUBKEY'] = $pair['public'];
        $_ENV['PLUGIN_ALLOW_UNSIGNED'] = '1';
        self::assertTrue(PluginSignature::isEnabled());
        self::assertFalse(PluginSignature::unsignedPluginsAllowed());

        $_ENV['PLUGIN_SIGNING_PUBKEY'] = 'broken';
        self::assertTrue(PluginSignature::isMisconfigured());
        self::assertFalse(PluginSignature::unsignedPluginsAllowed());
    }

    public function testUnreadableKeyFileIsMisconfigured(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root can read a mode-000 file');
        }
        $path = PluginSignature::publicKeyPath();
        self::assertFileExists($path);
        $mode = fileperms($path) & 0777;
        putenv('PLUGIN_ALLOW_UNSIGNED=1');
        chmod($path, 0000);
        try {
            clearstatcache();
            self::assertFalse(PluginSignature::isEnabled());
            self::assertTrue(PluginSignature::isMisconfigured());
            self::assertFalse(PluginSignature::unsignedPluginsAllowed());
        } finally {
            chmod($path, $mode);
            clearstatcache();
        }
        self::assertTrue(PluginSignature::isEnabled());
    }

    // ---- analytics salt ------------------------------------------------------

    public function testStoredPlaceholderSaltIsReplaced(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE analytics_settings (id INTEGER PRIMARY KEY AUTOINCREMENT, setting_key TEXT NOT NULL UNIQUE, setting_value TEXT, description TEXT, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $pdo->prepare('INSERT INTO analytics_settings (setting_key, setting_value) VALUES (?, ?)')
            ->execute(['ip_salt', AppSecret::PLACEHOLDER]);
        putenv('SESSION_SECRET=' . AppSecret::PLACEHOLDER);

        $service = new \App\Services\AnalyticsService($pdo);
        $salt = (new \ReflectionMethod($service, 'getIpSalt'))->invoke($service);

        self::assertNotSame(AppSecret::PLACEHOLDER, $salt);
        self::assertGreaterThanOrEqual(32, strlen((string) $salt));
        $stored = $pdo->query("SELECT setting_value FROM analytics_settings WHERE setting_key = 'ip_salt'")->fetchColumn();
        self::assertSame($salt, $stored, 'the placeholder row is healed in the DB');
    }

    public function testPaddedPlaceholderSaltIsHealedAndStableAcrossInstances(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE analytics_settings (id INTEGER PRIMARY KEY AUTOINCREMENT, setting_key TEXT NOT NULL UNIQUE, setting_value TEXT, description TEXT, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)');
        $pdo->prepare('INSERT INTO analytics_settings (setting_key, setting_value) VALUES (?, ?)')
            ->execute(['ip_salt', "\t" . AppSecret::PLACEHOLDER . "\n"]);

        $salt = static function () use ($pdo): string {
            $service = new \App\Services\AnalyticsService($pdo);
            return (string) (new \ReflectionMethod($service, 'getIpSalt'))->invoke($service);
        };
        $first = $salt();
        self::assertNotSame(AppSecret::PLACEHOLDER, trim($first));
        self::assertSame($first, $salt(), 'a second instance reuses the healed salt');
        self::assertSame($first, $pdo->query("SELECT setting_value FROM analytics_settings WHERE setting_key = 'ip_salt'")->fetchColumn());
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

    public function testUpdaterRefusesDowngrades(): void
    {
        self::assertTrue(Updater::isDowngrade('1.4.29', '1.4.30'));
        self::assertTrue(Updater::isDowngrade('v1.3.0', '1.4.30'));
        self::assertTrue(Updater::isDowngrade('1.4.30-rc.1', '1.4.30'));
        self::assertTrue(Updater::isDowngrade('1.4.29junk', '1.4.30'), 'unparseable target fails closed');
        self::assertTrue(Updater::isDowngrade('', '1.4.30'));

        self::assertFalse(Updater::isDowngrade('1.4.31', '1.4.30'));
        self::assertFalse(Updater::isDowngrade('v1.5.0', '1.4.30'));
        self::assertFalse(Updater::isDowngrade('1.4.30', '1.4.30'), 'a same-version reinstall is not a downgrade');
        self::assertFalse(Updater::isDowngrade('1.4.30', '1.4.30-dev'));
        self::assertFalse(Updater::isDowngrade('1.4.31', 'garbage'), 'a broken version.json must not block the repair');
    }

    public function testPerformUpdateRefusesADowngradeBeforeTouchingAnything(): void
    {
        // Isolated root with a known version, so the checkout's storage/ is
        // never touched and a stray maintenance file there cannot matter.
        file_put_contents($this->dir . '/version.json', json_encode(['version' => '1.4.30']));
        $ref = new \ReflectionClass(Updater::class);
        $updater = $ref->newInstanceWithoutConstructor();
        $ref->getProperty('db')->setValue($updater, new \App\Support\Database(database: $this->dir . '/db.sqlite', isSqlite: true));
        $ref->getProperty('rootPath')->setValue($updater, $this->dir);
        $ref->getProperty('backupPath')->setValue($updater, $this->dir . '/storage/backups');
        $ref->getProperty('tempPath')->setValue($updater, $this->dir . '/storage/tmp');
        $ref->getProperty('dockerDeployment')->setValue($updater, false);

        $result = $updater->performUpdate('1.4.29');

        self::assertFalse($result['success']);
        self::assertStringContainsString('downgrade', (string) $result['error']);
        self::assertNull($result['backup_path']);
        // Refused before the lock, maintenance mode and backup: nothing created.
        self::assertFileDoesNotExist($this->dir . '/storage/cache/update.lock');
        self::assertFileDoesNotExist($this->dir . '/storage/.maintenance');
        self::assertDirectoryDoesNotExist($this->dir . '/storage/backups');
    }

    public function testDatabaseBackupsAreOwnerOnlyAndPruned(): void
    {
        // Constructor-less so the readonly paths can point at the temp dir.
        // Deletions are confined to rootPath, so it is the temp dir too, with
        // the checkout's version.json (createBackup records it).
        copy(dirname(__DIR__, 2) . '/version.json', $this->dir . '/version.json');
        $ref = new \ReflectionClass(Updater::class);
        $updater = $ref->newInstanceWithoutConstructor();
        $backups = $this->dir . '/backups';
        $ref->getProperty('db')->setValue($updater, new \App\Support\Database(database: $this->dir . '/db.sqlite', isSqlite: true));
        $ref->getProperty('rootPath')->setValue($updater, $this->dir);
        $ref->getProperty('backupPath')->setValue($updater, $backups);

        // Seven older dumps, oldest first.
        for ($i = 1; $i <= 7; $i++) {
            $old = $backups . '/update_2020-01-0' . $i . '_000000';
            mkdir($old, 0755, true);
            file_put_contents($old . '/database.sql', '-- old');
            touch($old, 1577836800 + $i * 86400);
        }

        $result = $updater->createBackup();
        self::assertTrue($result['success'], (string) $result['error']);
        $dump = $result['path'] . '/database.sql';

        self::assertSame(0700, fileperms($backups) & 0777);
        self::assertSame(0700, fileperms((string) $result['path']) & 0777);
        self::assertSame(0600, fileperms($dump) & 0777);

        $left = array_map('basename', glob($backups . '/update_*', GLOB_ONLYDIR) ?: []);
        self::assertCount(Updater::BACKUPS_TO_KEEP, $left);
        self::assertContains(basename((string) $result['path']), $left, 'the dump just written is never pruned');
        self::assertNotContains('update_2020-01-01_000000', $left, 'the oldest dumps go first');
        self::assertContains('update_2020-01-07_000000', $left);
    }

    public function testPostUpdatePermissionPassKeepsDumpsOwnerOnly(): void
    {
        $ref = new \ReflectionClass(Updater::class);
        $updater = $ref->newInstanceWithoutConstructor();
        $ref->getProperty('rootPath')->setValue($updater, $this->dir);

        $dumpDir = $this->dir . '/storage/backups/update_2026-11-15_000000';
        mkdir($dumpDir, 0700, true);
        file_put_contents($dumpDir . '/database.sql', '-- dump');
        chmod($dumpDir . '/database.sql', 0600);
        mkdir($this->dir . '/storage/cache', 0700, true);
        file_put_contents($this->dir . '/storage/cache/x', 'x');
        chmod($this->dir . '/storage/cache/x', 0600);

        $ref->getMethod('fixPermissions')->invoke($updater);

        self::assertSame(0700, fileperms($this->dir . '/storage/backups') & 0777);
        self::assertSame(0700, fileperms($dumpDir) & 0777);
        self::assertSame(0600, fileperms($dumpDir . '/database.sql') & 0777);
        self::assertSame(0644, fileperms($this->dir . '/storage/cache/x') & 0777, 'everything else still gets the normal pass');
    }

    public function testPruneNeverRemovesTheProtectedBackupEvenIfItLooksOld(): void
    {
        $ref = new \ReflectionClass(Updater::class);
        $updater = $ref->newInstanceWithoutConstructor();
        $backups = $this->dir . '/backups';
        $ref->getProperty('backupPath')->setValue($updater, $backups);
        $ref->getProperty('rootPath')->setValue($updater, $this->dir);

        for ($i = 1; $i <= 4; $i++) {
            mkdir($backups . '/update_2020-01-0' . $i . '_000000', 0700, true);
            file_put_contents($backups . '/update_2020-01-0' . $i . '_000000/database.sql', '-- dump');
            touch($backups . '/update_2020-01-0' . $i . '_000000', 1577836800 + $i * 86400);
        }
        $protect = $backups . '/update_2020-01-01_000000';

        self::assertSame(2, $updater->pruneBackups(2, $protect));
        self::assertDirectoryExists($protect);
        self::assertDirectoryExists($backups . '/update_2020-01-04_000000');
        self::assertDirectoryDoesNotExist($backups . '/update_2020-01-02_000000');
    }

    // ---- fix-permissions.php: no token in the URL ------------------------------

    public function testFixPermissionsNeverReadsTheTokenFromTheQueryString(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/fix-permissions.php');
        self::assertDoesNotMatchRegularExpression('/\$authToken\s*=(?!=)[^;]*\$_GET/', $src);
        self::assertStringContainsString("\$_POST['token']", $src);
        self::assertStringContainsString('<form method="post"', $src);
    }

    /**
     * Served by php -S from a throwaway copy: __DIR__ is the temp dir, so even
     * an accepted token could only chmod the copy, never the checkout.
     */
    public function testIncompleteBackupDirectoriesNeverTakeARetentionSlot(): void
    {
        $ref = new \ReflectionClass(Updater::class);
        $updater = $ref->newInstanceWithoutConstructor();
        $backups = $this->dir . '/backups';
        $ref->getProperty('backupPath')->setValue($updater, $backups);
        $ref->getProperty('rootPath')->setValue($updater, $this->dir);

        // Five good dumps (oldest first), then four newer failed attempts.
        for ($i = 1; $i <= 5; $i++) {
            $d = $backups . '/update_2020-01-0' . $i . '_000000';
            mkdir($d, 0700, true);
            file_put_contents($d . '/database.sql', '-- dump');
            touch($d, 1577836800 + $i * 86400);
        }
        for ($i = 1; $i <= 4; $i++) {
            $d = $backups . '/update_2021-01-0' . $i . '_000000';
            mkdir($d, 0700, true);
            touch($d, 1609459200 + $i * 86400);
        }
        $new = $backups . '/update_2022-01-01_000000';
        mkdir($new, 0700, true);
        file_put_contents($new . '/database.sql', '-- dump');

        self::assertSame(1, $updater->pruneBackups(5, $new), 'only the oldest good dump goes');
        self::assertDirectoryDoesNotExist($backups . '/update_2020-01-01_000000');
        for ($i = 2; $i <= 5; $i++) {
            self::assertDirectoryExists($backups . '/update_2020-01-0' . $i . '_000000');
        }
        self::assertDirectoryExists($new);
    }

    public function testFailedBackupLeavesNoDirectoryBehind(): void
    {
        copy(dirname(__DIR__, 2) . '/version.json', $this->dir . '/version.json');
        $ref = new \ReflectionClass(Updater::class);
        $updater = $ref->newInstanceWithoutConstructor();
        $backups = $this->dir . '/backups';
        $ref->getProperty('db')->setValue($updater, new \App\Support\Database(database: $this->dir . '/db.sqlite', isSqlite: true));
        $ref->getProperty('rootPath')->setValue($updater, $this->dir);
        $ref->getProperty('backupPath')->setValue($updater, $backups);

        // Make the dump fail: for the next few seconds' backup names,
        // database.sql already exists as a directory, so it cannot be opened.
        $now = time();
        for ($t = $now; $t <= $now + 3; $t++) {
            mkdir($backups . '/update_' . date('Y-m-d_His', $t) . '/database.sql', 0700, true);
        }
        $before = count(glob($backups . '/update_*') ?: []);

        $result = $updater->createBackup();

        self::assertFalse($result['success']);
        self::assertCount($before - 1, glob($backups . '/update_*') ?: [], 'the failed attempt directory is removed');
    }

    public function testShortDumpWriteIsAnError(): void
    {
        $ref = new \ReflectionClass(Updater::class);
        $updater = $ref->newInstanceWithoutConstructor();
        $file = $this->dir . '/ro.sql';
        file_put_contents($file, '');
        $handle = fopen($file, 'r'); // writes fail on a read-only handle
        self::assertIsResource($handle);
        try {
            $this->expectException(\Exception::class);
            @$ref->getMethod('dumpWrite')->invoke($updater, $handle, 'INSERT ...');
        } finally {
            fclose($handle);
        }
    }

    public function testFixPermissionsRejectsAQueryTokenOverHttp(): void
    {
        $site = $this->dir . '/fixperm';
        mkdir($site . '/storage/tmp', 0700, true);
        copy(dirname(__DIR__, 2) . '/fix-permissions.php', $site . '/fix-permissions.php');
        $token = bin2hex(random_bytes(12));
        file_put_contents($site . '/storage/tmp/permissions_fix_token.txt', $token);

        $port = 20000 + random_int(0, 20000);
        $proc = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $site],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes
        );
        self::assertIsResource($proc);
        try {
            $base = 'http://127.0.0.1:' . $port . '/fix-permissions.php';
            $deadline = microtime(true) + 5;
            while (@fsockopen('127.0.0.1', $port) === false && microtime(true) < $deadline) {
                usleep(50000);
            }

            [$status, $body] = $this->http($base . '?token=' . $token);
            self::assertSame(400, $status, 'a correct token in the URL is refused');
            self::assertStringContainsString('<form method="post"', $body);
            self::assertStringNotContainsString('Permission Repair Complete', $body, 'nothing was fixed');

            [$status, $body] = $this->http($base);
            self::assertSame(200, $status);
            self::assertStringContainsString('name="token"', $body);

            [$status] = $this->http($base, 'token=wrong');
            self::assertSame(401, $status);

            // A real run must leave database dumps owner-only.
            $dumpDir = $site . '/storage/backups/update_2026-11-16_000000';
            mkdir($dumpDir, 0700, true);
            chmod($site . '/storage/backups', 0700);
            file_put_contents($dumpDir . '/database.sql', '-- dump');
            chmod($dumpDir . '/database.sql', 0600);
            [$status, $body] = $this->http($base, 'token=' . $token);
            self::assertSame(200, $status, substr($body, 0, 300));
            clearstatcache();
            self::assertSame(0700, fileperms($site . '/storage/backups') & 0777);
            self::assertSame(0700, fileperms($dumpDir) & 0777);
            self::assertSame(0600, fileperms($dumpDir . '/database.sql') & 0777);
        } finally {
            proc_terminate($proc);
            proc_close($proc);
        }
    }

    /** @return array{0: int, 1: string} */
    private function http(string $url, ?string $post = null): array
    {
        $opts = ['http' => ['ignore_errors' => true, 'timeout' => 5, 'method' => $post === null ? 'GET' : 'POST']];
        if ($post !== null) {
            $opts['http']['header'] = "Content-Type: application/x-www-form-urlencoded\r\n";
            $opts['http']['content'] = $post;
        }
        $body = (string) @file_get_contents($url, false, stream_context_create($opts));
        $status = 0;
        foreach ($http_response_header as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                $status = (int) $m[1];
            }
        }
        return [$status, $body];
    }

    public function testGitignoreCoversEnvVariantsAndSqliteSideFiles(): void
    {
        $root = dirname(__DIR__, 2);
        $check = static function (string $path) use ($root): bool {
            exec('git -C ' . escapeshellarg($root) . ' check-ignore -q ' . escapeshellarg($path) . ' 2>/dev/null', $o, $code);
            // 0 = ignored, 1 = not ignored; anything else (128 dubious
            // ownership in containers, 127 git missing) says nothing about
            // .gitignore, so the test is skipped instead of failing.
            if ($code !== 0 && $code !== 1) {
                self::markTestSkipped('git check-ignore unavailable (exit ' . $code . ')');
            }
            return $code === 0;
        };
        if (!is_dir($root . '/.git') && !is_file($root . '/.git')) {
            self::markTestSkipped('not a git checkout');
        }
        foreach (['.env', '.env.production', '.env.old', 'database/database.sqlite-wal', 'database/database.sqlite-shm', 'database/database.sqlite-journal', 'storage/backups/x/database.sql', 'resources/keys/plugin-signing.key'] as $secret) {
            self::assertTrue($check($secret), $secret . ' must be ignored');
        }
        self::assertFalse($check('.env.example'), '.env.example ships');
        self::assertFalse($check('resources/keys/plugin-signing.pub'), 'the public key ships');
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

    /**
     * Every EXIF orientation, mirrored ones included, must move each source
     * pixel to where the tag says it is displayed. Source is a 3x2 image with
     * a distinct colour per pixel, so any wrong flip or turn shows up.
     */
    public function testAllEightExifOrientationsAreApplied(): void
    {
        $w = 3;
        $h = 2;
        // Where source pixel (x, y) must land after applying orientation N.
        $map = [
            1 => fn (int $x, int $y): array => [$x, $y],
            2 => fn (int $x, int $y): array => [$w - 1 - $x, $y],
            3 => fn (int $x, int $y): array => [$w - 1 - $x, $h - 1 - $y],
            4 => fn (int $x, int $y): array => [$x, $h - 1 - $y],
            5 => fn (int $x, int $y): array => [$y, $x],
            6 => fn (int $x, int $y): array => [$h - 1 - $y, $x],
            7 => fn (int $x, int $y): array => [$h - 1 - $y, $w - 1 - $x],
            8 => fn (int $x, int $y): array => [$y, $w - 1 - $x],
        ];
        foreach ($map as $orientation => $target) {
            $img = imagecreatetruecolor($w, $h);
            self::assertNotFalse($img);
            for ($y = 0; $y < $h; $y++) {
                for ($x = 0; $x < $w; $x++) {
                    imagesetpixel($img, $x, $y, ($x * 40 + 10) << 16 | ($y * 90 + 20) << 8);
                }
            }
            $out = ImageMetadataStripper::applyOrientation($img, $orientation);
            self::assertNotFalse($out);
            $swap = $orientation >= 5;
            self::assertSame($swap ? $h : $w, imagesx($out), "width, orientation $orientation");
            for ($y = 0; $y < $h; $y++) {
                for ($x = 0; $x < $w; $x++) {
                    [$tx, $ty] = $target($x, $y);
                    self::assertSame(
                        ($x * 40 + 10) << 16 | ($y * 90 + 20) << 8,
                        imagecolorat($out, $tx, $ty),
                        "orientation $orientation, source ($x,$y)"
                    );
                }
            }
            imagedestroy($out);
        }
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
