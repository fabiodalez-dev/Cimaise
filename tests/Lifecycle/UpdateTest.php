<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Support/AppSandbox.php';

/**
 * Updates an installed site to the current tree through the real updater:
 * release lookup, package download, checksum, backup, file replacement,
 * migrations and cache refresh. GitHub is replaced by a local server that
 * publishes a package built from this checkout.
 */
final class UpdateTest extends TestCase
{
    private const ADMIN_EMAIL = 'admin@update.test';
    private const ADMIN_PASSWORD = 'Update-Test-123!';
    private const OLD_VERSION = '1.4.23';
    private const STALE_MARKER = 'OLD-RELEASE-MARKER';

    private AppSandbox $site;
    private string $releaseDir;
    /** @var resource|null */
    private $github = null;
    private string $githubUrl = '';
    private string $targetVersion;

    protected function setUp(): void
    {
        if (!class_exists(ZipArchive::class)) {
            self::markTestSkipped('ext-zip is required');
        }
        $this->targetVersion = (string) json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/version.json'), true)['version'];
        $this->site = AppSandbox::create();
        $this->releaseDir = sys_get_temp_dir() . '/cimaise_release_' . bin2hex(random_bytes(6));
        mkdir($this->releaseDir, 0775, true);
    }

    protected function tearDown(): void
    {
        if (is_resource($this->github)) {
            proc_terminate($this->github, 9);
            proc_close($this->github);
        }
        $this->site->destroy();
        TestFilesystem::remove($this->releaseDir);
    }

    /** Zip the current tree the way a release package is laid out and publish it on a fake GitHub API. */
    private function publishRelease(): void
    {
        $package = AppSandbox::create();
        try {
            $name = 'cimaise-v' . $this->targetVersion;
            $zip = new ZipArchive();
            self::assertTrue($zip->open($this->releaseDir . '/' . $name . '.zip', ZipArchive::CREATE));
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($package->root, FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                $relative = substr($file->getPathname(), strlen($package->root) + 1);
                if ($file->isFile() && !str_starts_with($relative, '.')) {
                    $zip->addFile($file->getPathname(), $name . '/' . $relative);
                    $zip->setCompressionName($name . '/' . $relative, ZipArchive::CM_STORE);
                }
            }
            self::assertTrue($zip->close());
        } finally {
            $package->destroy();
        }

        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($socket);
        $port = (int) substr(strrchr((string) stream_socket_get_name($socket, false), ':') ?: ':0', 1);
        fclose($socket);
        $this->githubUrl = 'http://127.0.0.1:' . $port;

        $zipFile = $this->releaseDir . '/' . $name . '.zip';
        file_put_contents($this->releaseDir . '/release.json', json_encode([
            'tag_name' => 'v' . $this->targetVersion,
            'name' => 'Cimaise v' . $this->targetVersion,
            'body' => 'Test release',
            'draft' => false,
            'prerelease' => false,
            'published_at' => gmdate('c'),
            'html_url' => $this->githubUrl . '/release',
            'assets' => [[
                'name' => $name . '.zip',
                'size' => filesize($zipFile),
                'digest' => 'sha256:' . hash_file('sha256', $zipFile),
                'browser_download_url' => $this->githubUrl . '/download/' . $name . '.zip',
            ]],
        ], JSON_THROW_ON_ERROR));
        // Just enough of the GitHub REST API for the updater.
        file_put_contents($this->releaseDir . '/router.php', <<<'PHP'
<?php
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$release = (string) file_get_contents(__DIR__ . '/release.json');
if (preg_match('#^/download/(cimaise-v[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?\.zip)$#', $path, $m) && is_file(__DIR__ . '/' . $m[1])) {
    header('Content-Type: application/zip');
    readfile(__DIR__ . '/' . $m[1]);
} elseif (preg_match('#^/repos/[^/]+/[^/]+/releases/(latest|tags/v[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?)$#', $path)) {
    header('Content-Type: application/json');
    echo $release;
} elseif (preg_match('#^/repos/[^/]+/[^/]+/releases$#', $path)) {
    header('Content-Type: application/json');
    echo '[' . $release . ']';
} else {
    http_response_code(404);
    echo '{"message":"Not Found"}';
}
PHP);
        // nosemgrep: php.lang.security.exec-use.exec-use -- fixed argv (no shell): PHP's built-in server on a loopback port, serving this fixture's temp directory.
        $this->github = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, $this->releaseDir . '/router.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            $this->releaseDir
        );
        self::assertIsResource($this->github);
        $deadline = microtime(true) + 10;
        do {
            self::assertTrue(proc_get_status($this->github)['running'], 'fake GitHub API exited (port taken?)');
            $probe = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);
            usleep(50000);
        } while ($probe === false && microtime(true) < $deadline);
        self::assertNotFalse($probe, 'fake GitHub API did not start');
        fclose($probe);
    }

    /** Turn the freshly installed site into an older release that holds real content. */
    private function ageSite(): void
    {
        $root = $this->site->root;
        $version = json_decode((string) file_get_contents($root . '/version.json'), true);
        $version['version'] = self::OLD_VERSION;
        file_put_contents($root . '/version.json', json_encode($version, JSON_PRETTY_PRINT));

        // Schema as it was before the newest column was introduced.
        $db = $this->site->database();
        $db->exec('ALTER TABLE albums DROP COLUMN show_equipment');
        $db->exec("INSERT INTO categories (id, name, slug) VALUES (9001, 'Kept', 'kept')");
        $db->exec("INSERT INTO albums (id, title, slug, category_id, is_published, custom_cameras) VALUES (9001, 'Kept album', 'kept-album', 9001, 1, 'Kept camera')");
        $db = null;

        // A template the old release shipped and the new one replaces.
        foreach (['_layout.twig', '_layout_modern.twig'] as $layout) {
            $file = $root . '/app/Views/frontend/' . $layout;
            file_put_contents($file, str_replace('</body>', '<!-- ' . self::STALE_MARKER . ' --></body>', (string) file_get_contents($file)));
        }

        // Things an update must leave alone.
        file_put_contents($root . '/storage/originals/kept-original.jpg', 'ORIGINAL-BYTES');
        $pack = $root . '/storage/translations/en.json';
        $translations = json_decode((string) file_get_contents($pack), true);
        $group = array_key_first(array_filter($translations, static fn ($v, $k) => $k !== '_meta' && is_array($v) && count($v) > 2, ARRAY_FILTER_USE_BOTH));
        $keys = array_keys($translations[$group]);
        $translations[$group][$keys[0]] = 'EDITED BY THE ADMIN';
        unset($translations[$group][$keys[1]]);
        file_put_contents($pack, json_encode($translations, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        file_put_contents($root . '/.translation-probe', json_encode([$group, $keys[0], $keys[1]]));
    }

    public function testUpdaterBringsAnOlderSiteToTheCurrentRelease(): void
    {
        $this->site->startServer();
        $this->site->installThroughWizard(self::ADMIN_EMAIL, self::ADMIN_PASSWORD, 'Update Test Site');
        $this->ageSite();
        $envBefore = (string) file_get_contents($this->site->root . '/.env');

        // The old release is live and has compiled its own templates.
        $before = $this->site->request('/');
        self::assertSame(200, $before['status'], substr($before['body'], 0, 500));
        self::assertStringContainsString(self::STALE_MARKER, $before['body']);

        $this->publishRelease();
        [$exit, $output] = $this->site->php(<<<'PHP'
$container = require __DIR__ . '/app/Config/bootstrap.php';
$updater = new App\Support\Updater($container['db']);
$check = $updater->checkForUpdates();
$result = ($check['available'] ?? false)
    ? $updater->performUpdate((string) getenv('TARGET_VERSION'))
    : ['success' => false, 'error' => 'no update offered'];
echo "\nRESULT:" . json_encode(['from' => $check['current'] ?? null, 'offered' => $check['latest'] ?? null, 'success' => $result['success'] ?? false, 'error' => $result['error'] ?? null]);
PHP, ['UPDATER_API_BASE' => $this->githubUrl, 'UPDATER_REPO' => 'test/cimaise', 'TARGET_VERSION' => $this->targetVersion]);
        self::assertSame(0, $exit, $output);
        self::assertSame(1, preg_match('/RESULT:(\{.*\})\s*$/', $output, $match), $output);
        $result = json_decode($match[1], true);
        self::assertTrue($result['success'], $output);

        $root = $this->site->root;
        // Code and schema are the new release's.
        self::assertSame($this->targetVersion, json_decode((string) file_get_contents($root . '/version.json'), true)['version']);
        self::assertStringNotContainsString(self::STALE_MARKER, (string) file_get_contents($root . '/app/Views/frontend/_layout.twig'));
        $db = $this->site->database();
        self::assertContains('show_equipment', array_column($db->query('PRAGMA table_info(albums)')->fetchAll(PDO::FETCH_ASSOC), 'name'), 'pending migration applied');
        self::assertFileDoesNotExist($root . '/storage/.maintenance', 'maintenance mode released');

        // The site's own content and configuration are untouched.
        self::assertSame($envBefore, (string) file_get_contents($root . '/.env'));
        self::assertSame('Kept camera', $db->query('SELECT custom_cameras FROM albums WHERE id = 9001')->fetchColumn());
        self::assertSame(1, (int) $db->query('SELECT show_equipment FROM albums WHERE id = 9001')->fetchColumn(), 'new column takes its default on existing rows');
        self::assertSame(1, (int) $db->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn());
        self::assertSame('ORIGINAL-BYTES', file_get_contents($root . '/storage/originals/kept-original.jpg'));
        [$group, $edited, $removed] = json_decode((string) file_get_contents($root . '/.translation-probe'), true);
        $translations = json_decode((string) file_get_contents($root . '/storage/translations/en.json'), true);
        self::assertSame('EDITED BY THE ADMIN', $translations[$group][$edited], 'admin-edited text survives');
        self::assertArrayHasKey($removed, $translations[$group], 'keys added by the release are merged in');

        // The updated site serves the new templates, not the ones compiled before.
        $after = $this->site->request('/');
        self::assertSame(200, $after['status'], substr($after['body'], 0, 500));
        self::assertStringNotContainsString(self::STALE_MARKER, $after['body'], 'compiled templates of the old release must not be served');
        self::assertStringNotContainsStringIgnoringCase('fatal error', $after['body']);
        self::assertSame(200, $this->site->request('/album/kept-album')['status']);

        // The admin still signs in and the updater reports nothing left to do.
        $this->site->forgetCookies();
        $login = $this->site->request('/admin/login');
        self::assertSame(1, preg_match('/name="csrf" value="([^"]+)"/', $login['body'], $csrf));
        self::assertSame(302, $this->site->request('/admin/login', ['csrf' => $csrf[1], 'email' => self::ADMIN_EMAIL, 'password' => self::ADMIN_PASSWORD])['status']);
        self::assertSame(200, $this->site->request('/admin')['status']);
        self::assertSame(200, $this->site->request('/admin/updates')['status']);
    }
}
