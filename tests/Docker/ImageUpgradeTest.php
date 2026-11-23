<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Upgrades a real container: an install created by an older published image
 * is started with the image under test on the same volumes, then an upload is
 * made over HTTP and the image worker must generate its variants.
 *
 *   CIMAISE_DOCKER_IMAGE=fabiodalez/cimaise:1.4.26 vendor/bin/phpunit -c phpunit.xml tests/Docker/ImageUpgradeTest.php
 *
 * CIMAISE_DOCKER_BASELINE (default fabiodalez/cimaise:1.4.25) is the image the
 * install starts from. Skipped unless CIMAISE_DOCKER_IMAGE is set and a Docker
 * engine answers.
 */
final class ImageUpgradeTest extends TestCase
{
    private const EMAIL = 'admin@docker.test';
    private const PASSWORD = 'Docker-Test-123!';

    private string $name;
    private int $port = 0;
    private string $cookieJar;

    protected function setUp(): void
    {
        if ((string) getenv('CIMAISE_DOCKER_IMAGE') === '') {
            self::markTestSkipped('Set CIMAISE_DOCKER_IMAGE to run the container upgrade test');
        }
        if ($this->docker(['version', '--format', '{{.Server.Version}}'])[0] !== 0) {
            self::markTestSkipped('No Docker engine available');
        }
        $this->name = 'cimaise-suite-' . bin2hex(random_bytes(4));
        $this->cookieJar = (string) tempnam(sys_get_temp_dir(), 'cimaise-docker-cookies');
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertNotFalse($socket);
        $this->port = (int) substr(strrchr((string) stream_socket_get_name($socket, false), ':') ?: ':0', 1);
        fclose($socket);
    }

    protected function tearDown(): void
    {
        if (!isset($this->name)) {
            return;
        }
        $this->docker(['rm', '-f', $this->name]);
        $this->docker(['volume', 'rm', '-f', $this->name . '-storage', $this->name . '-db', $this->name . '-media']);
        @unlink($this->cookieJar); // nosemgrep: php.lang.security.unlink-use.unlink-use -- path returned by tempnam() in setUp.
    }

    /**
     * @param string[] $arguments
     * @return array{0: int, 1: string}
     */
    private function docker(array $arguments, ?string $stdin = null): array
    {
        // nosemgrep: php.lang.security.exec-use.exec-use -- fixed argv (no shell): the docker CLI with arguments built by this test.
        $process = @proc_open(array_merge(['docker'], $arguments), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
        if (!is_resource($process)) {
            return [127, ''];
        }
        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        return [proc_close($process), $output];
    }

    private function start(string $image): void
    {
        $this->docker(['rm', '-f', $this->name]);
        [$exit, $output] = $this->docker([
            'run', '-d', '--name', $this->name, '-p', '127.0.0.1:' . $this->port . ':80',
            '-v', $this->name . '-storage:/var/www/html/storage',
            '-v', $this->name . '-db:/var/www/html/database',
            '-v', $this->name . '-media:/var/www/html/public/media',
            $image,
        ]);
        self::assertSame(0, $exit, $output);
        $this->waitFor(fn (): bool => $this->http('/')['status'] > 0, 90, 'container did not answer on HTTP: ' . $this->docker(['logs', $this->name])[1]);
    }

    private function waitFor(callable $condition, int $seconds, string $message): void
    {
        $deadline = time() + $seconds;
        while (time() < $deadline) {
            if ($condition()) {
                return;
            }
            usleep(500000);
        }
        self::fail($message);
    }

    /**
     * @param array<string, string|CURLFile>|null $post
     * @param string[] $headers
     * @return array{status: int, body: string}
     */
    private function http(string $path, ?array $post = null, array $headers = [], bool $multipart = false): array
    {
        $curl = curl_init('http://127.0.0.1:' . $this->port . $path);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 120,
            CURLOPT_COOKIEJAR => $this->cookieJar, CURLOPT_COOKIEFILE => $this->cookieJar, CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($post !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $multipart ? $post : http_build_query($post));
        }
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        return ['status' => $status, 'body' => is_string($body) ? $body : ''];
    }

    private function exec(string $shell): string
    {
        [$exit, $output] = $this->docker(['exec', '-u', 'www-data', $this->name, 'sh', '-c', $shell]);
        self::assertSame(0, $exit, $shell . "\n" . $output);
        return $output;
    }

    public function testVolumesFromAnOlderImageUpgradeAndUploadsGetTheirVariants(): void
    {
        $baseline = (string) (getenv('CIMAISE_DOCKER_BASELINE') ?: 'fabiodalez/cimaise:1.4.25');
        $candidate = (string) getenv('CIMAISE_DOCKER_IMAGE');

        // An install made by the older image, with an album to upload into.
        $this->start($baseline);
        $this->exec('cp database/template.sqlite database/database.sqlite'
            . ' && printf "APP_ENV=production\nAPP_DEBUG=false\nAPP_URL=http://127.0.0.1:' . $this->port . '\nDB_CONNECTION=sqlite\nDB_DATABASE=database/database.sqlite\nSESSION_SECRET=%s\n" "$(head -c 24 /dev/urandom | od -An -tx1 | tr -d " \n")" > storage/.env'
            . ' && php bin/console user:create ' . escapeshellarg(self::EMAIL) . ' ' . escapeshellarg(self::PASSWORD));
        $this->docker(['exec', '-i', '-u', 'www-data', $this->name, 'php'], <<<'PHP'
<?php
$pdo = new PDO('sqlite:/var/www/html/database/database.sqlite');
$pdo->exec("INSERT INTO categories (id, name, slug) VALUES (900, 'Suite', 'suite')");
$pdo->exec("INSERT INTO albums (id, title, slug, category_id, is_published) VALUES (900, 'Suite album', 'suite-album', 900, 1)");
PHP);
        self::assertSame(0, $this->docker(['restart', $this->name])[0]);
        $this->waitFor(fn (): bool => $this->http('/')['status'] === 200, 90, 'baseline install does not serve the home page');

        // Same volumes, image under test.
        $this->start($candidate);
        $this->waitFor(fn (): bool => $this->http('/')['status'] === 200, 90, 'upgraded container does not serve the home page: ' . $this->docker(['logs', $this->name])[1]);
        $logs = $this->docker(['logs', $this->name])[1];
        self::assertStringContainsString('migrations applied', $logs);
        self::assertStringContainsString('starting image worker', $logs);
        // The log line is printed even when nothing was applied: the schema
        // recorded in the database must be the newest one this image ships.
        $this->docker(['exec', '-i', '-u', 'www-data', $this->name, 'sh', '-c', 'cat > /tmp/schema-check.php'], <<<'PHP'
<?php
$image = json_decode((string) file_get_contents('/var/www/html/version.json'), true)['version'];
$newest = '0';
foreach (glob('/usr/share/cimaise/migrations/migrate_*_sqlite.sql') ?: [] as $file) {
    $version = preg_replace('/^migrate_(.+)_sqlite\.sql$/', '$1', basename($file));
    if (version_compare($version, $image, '<=') && version_compare($version, $newest, '>')) {
        $newest = $version;
    }
}
$pdo = new PDO('sqlite:/var/www/html/database/database.sqlite');
$stored = json_decode((string) $pdo->query("SELECT value FROM settings WHERE key = 'docker.schema_version'")->fetchColumn(), true);
$columns = array_column($pdo->query('PRAGMA table_info(albums)')->fetchAll(PDO::FETCH_ASSOC), 'name');
$layoutColumns = array_column($pdo->query('PRAGMA table_info(album_layouts)')->fetchAll(PDO::FETCH_ASSOC), 'name');
echo json_encode(['newest' => $newest, 'stored' => $stored, 'show_equipment' => in_array('show_equipment', $columns, true), 'album_layouts' => $layoutColumns]);
PHP);
        $schema = json_decode($this->exec('php /tmp/schema-check.php'), true);
        self::assertSame($schema['newest'], $schema['stored'], 'database schema version after the upgrade');
        self::assertTrue($schema['show_equipment'], 'albums.show_equipment exists after the upgrade');
        // 1.5.0: visual album layouts (database/migrations/migrate_1.5.0_*).
        foreach (['album_id', 'draft_json', 'published_json', 'revision', 'updated_at', 'published_at'] as $column) {
            self::assertContains($column, $schema['album_layouts'], 'album_layouts.' . $column . ' exists after the upgrade');
        }
        self::assertSame(200, $this->http('/album/suite-album')['status'], 'content created by the older image is still served');

        // A real upload, as the admin UI sends it.
        $login = $this->http('/admin/login');
        self::assertSame(1, preg_match('/name="csrf" value="([^"]+)"/', $login['body'], $csrf));
        self::assertSame(302, $this->http('/admin/login', ['csrf' => $csrf[1], 'email' => self::EMAIL, 'password' => self::PASSWORD])['status']);
        $edit = $this->http('/admin/albums/900/edit');
        self::assertSame(200, $edit['status']);
        self::assertSame(1, preg_match('/name="csrf" value="([^"]+)"/', $edit['body'], $csrf));

        $photo = sys_get_temp_dir() . '/cimaise-suite-' . bin2hex(random_bytes(4)) . '.jpg';
        $canvas = imagecreatetruecolor(1600, 1200);
        for ($i = 0; $i < 400; $i++) {
            imagefilledrectangle($canvas, random_int(0, 1500), random_int(0, 1100), random_int(100, 1600), random_int(100, 1200), imagecolorallocate($canvas, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
        }
        imagejpeg($canvas, $photo, 88);
        try {
            $upload = $this->http('/admin/albums/900/upload', ['csrf' => $csrf[1], 'file' => new CURLFile($photo, 'image/jpeg', 'suite.jpg')],
                ['X-CSRF-Token: ' . $csrf[1], 'Accept: application/json'], true);
        } finally {
            @unlink($photo); // nosemgrep: php.lang.security.unlink-use.unlink-use -- temp file created a few lines above.
        }
        self::assertSame(200, $upload['status'], $upload['body']);
        $imageId = (int) (json_decode($upload['body'], true)['id'] ?? 0);
        self::assertGreaterThan(0, $imageId, $upload['body']);

        // mod_php cannot work after the response: the job waits for the worker.
        $this->waitFor(
            fn (): bool => trim($this->exec('ls storage/image-jobs 2>/dev/null | grep -c "\\.job$" || true')) === '0',
            240,
            'the image worker did not empty the queue: ' . $this->exec('ls storage/image-jobs; tail -5 storage/logs/*.log 2>/dev/null || true')
        );
        $files = array_filter(explode("\n", $this->exec('ls public/media')), static fn (string $f): bool => str_starts_with($f, $imageId . '_'));
        foreach (['sm', 'md', 'lg', 'xl', 'xxl'] as $size) {
            self::assertContains($imageId . '_' . $size . '.jpg', $files, 'missing ' . $size . ' variant');
        }
        self::assertNotEmpty(preg_grep('/\.webp$/', $files), 'no WebP variant');

        // Idle again: the worker must not keep a PHP process running.
        self::assertSame('0', trim($this->exec('n=0; for p in /proc/[0-9]*; do c=$(tr "\0" " " < $p/cmdline 2>/dev/null); case "$c" in php\ *) n=$((n+1));; esac; done; echo $n')));
    }
}
