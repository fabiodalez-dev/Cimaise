<?php

declare(strict_types=1);

// Run only in a disposable checkout/container, never against a live installation.
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use App\Services\ImageJobQueue;
use App\Services\SettingsService;
use App\Services\UploadService;
use App\Support\Database;
use App\Support\ImageProcessingLock;
use App\Tasks\ImagesWorkCommand;
use App\Tasks\ImagesGenerateCommand;
use App\Tasks\ImagesGenerateVariantsCommand;
use Symfony\Component\Console\Tester\CommandTester;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "PASS: {$message}\n";
}

function envv(string $key, mixed $default = null): mixed { return $_ENV[$key] ?? $_SERVER[$key] ?? (getenv($key) !== false ? getenv($key) : $default); }

$root = dirname(__DIR__, 2);
$directory = $root . '/storage/tmp/image-test-' . bin2hex(random_bytes(6));
mkdir($directory, 0775, true);
$db = new Database(database: $directory . '/test.sqlite', isSqlite: true);
$pdo = $db->pdo();
$pdo->exec(file_get_contents($root . '/database/schema.sqlite.sql'));
$pdo->exec("INSERT INTO albums(id, title, slug, category_id) VALUES(987654321, 'Image test', 'image-test', 1)");
$pdo->exec("INSERT OR REPLACE INTO sqlite_sequence(name, seq) VALUES('images', 987654321)");
$settings = new SettingsService($db);
$settings->set('image.formats', ['jpg' => true, 'webp' => true, 'avif' => true]);
$settings->set('image.breakpoints', ['sm' => 48, 'md' => 96, 'lg' => 144]);
$settings->set('image.variants_async', true);
$upload = new UploadService($db);
$queue = new ImageJobQueue($db);
$ids = [];
$originals = [];

try {
    $fixture = imagecreatetruecolor(180, 120);
    imagefill($fixture, 0, 0, imagecolorallocate($fixture, 33, 88, random_int(100, 255)));
    imagejpeg($fixture, $directory . '/upload.jpg');
    imagedestroy($fixture);
    $meta = $upload->ingestAlbumUpload(987654321, ['tmp_name' => $directory . '/upload.jpg', 'error' => 0]);
    $id = (int)$meta['id'];
    $ids[] = $id;
    $originals[] = $meta['path'];
    $job = $root . '/storage/image-jobs/' . $id . '.job';
    check(is_file($job), 'asynchronous upload persists its job');
    check(!is_file($root . '/public/media/' . $id . '_lg.jpg'), 'asynchronous upload defers larger variants');
    check(array_values(array_filter($queue->status(), fn ($job) => $job['id'] === $id))[0]['state'] === 'queued', 'queued progress is visible before processing');
    $previousRevision = \App\Services\ImageGenerationRevision::current();
    check($queue->drain()['failed'] === 0, 'queued image generates without errors');
    check(\App\Services\ImageGenerationRevision::current() !== $previousRevision, 'completed generation advances the public opaque revision');
    check(!is_file($job), 'successful job is removed');
    $progress = array_values(array_filter($queue->status(), fn ($job) => $job['id'] === $id))[0];
    check($progress['state'] === 'complete' && $progress['completed'] === 10 && $progress['total'] === 10, 'progress counts all nine variants and the placeholder');
    foreach (['sm', 'md', 'lg'] as $size) {
        foreach (['jpg', 'webp', 'avif'] as $format) {
            check(is_file($root . '/public/media/' . $id . '_' . $size . '.' . $format), "generates {$size}.{$format}");
        }
    }
    check($upload->generateVariantsForImage($id)['generated'] === 0, 'repeat generation skips existing variants');

    ImageProcessingLock::run($id, function () use ($upload, $id): void {
        try {
            $upload->generateVariantsForImage($id);
            throw new LogicException('Expected processing conflict');
        } catch (RuntimeException $e) {
            check(str_contains($e->getMessage(), 'already being processed'), 'concurrent generation is rejected');
        }
    });
    $queue->enqueue($id);
    rename($meta['path'], $meta['path'] . '.hold');
    $previousRevision = \App\Services\ImageGenerationRevision::current();
    check($queue->drain()['failed'] === 1 && is_file($job), 'failed job remains queued');
    check(\App\Services\ImageGenerationRevision::current() === $previousRevision, 'failed generation does not announce enhanced versions');
    $state = json_decode(file_get_contents($job), true);
    check($state['attempts'] === 1 && $state['next_at'] > time(), 'failed job receives retry backoff');
    $progress = array_values(array_filter($queue->status(), fn ($job) => $job['id'] === $id))[0];
    check($progress['state'] === 'retrying' && !isset($progress['error']), 'retry status does not expose internal error paths');
    foreach ([new ImagesGenerateCommand($db), new ImagesGenerateVariantsCommand($db)] as $command) {
        $tester = new CommandTester($command);
        check($tester->execute(['--image' => $id]) === 1, 'CLI generation reports failure for missing original');
    }
    $state['next_at'] = 0;
    file_put_contents($job, json_encode($state));
    $workerTester = new CommandTester(new ImagesWorkCommand($db));
    check($workerTester->execute(['--quiet-mode' => true]) === 1, 'queue CLI reports encoding errors as failure');
    rename($meta['path'] . '.hold', $meta['path']);
    $state['next_at'] = 0;
    file_put_contents($job, json_encode($state));
    check((new ImageJobQueue($db))->drain()['failed'] === 0 && !is_file($job), 'a new worker resumes a failed/interrupted job');

    $settings->set('image.variants_async', false);
    copy($meta['path'], $directory . '/sync.jpg');
    $sync = $upload->ingestAlbumUpload(987654321, ['tmp_name' => $directory . '/sync.jpg', 'error' => 0]);
    $ids[] = (int)$sync['id'];
    check(is_file($root . '/public/media/' . $sync['id'] . '_lg.jpg'), 'disabled async setting generates before returning');

    $pdo->exec('UPDATE albums SET is_nsfw = 1 WHERE id = 987654321');
    check($upload->generateVariantsForImage($id, true)['failed'] === 0, 'protected image generation succeeds');
    check(is_file($root . '/storage/protected-media/' . $id . '_lg.jpg'), 'protected variants remain outside public media');
    check(!is_file($root . '/public/media/' . $id . '_lg.jpg'), 'protected variants have no public copy');
    $queue->enqueue($id);
    check($queue->drain()['failed'] === 0 && !is_file($job), 'protected queued job completes without a public LQIP');
    $progress = array_values(array_filter($queue->status(), fn ($job) => $job['id'] === $id))[0];
    check($progress['state'] === 'complete' && $progress['total'] === 10, 'protected progress counts variants and its private blurred preview');

    $controller = new \App\Controllers\Admin\MediaController($db, \Slim\Views\Twig::create($root . '/app/Views'), new \App\Services\ExifService($db));
    $request = (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', '/admin/media/images/' . $id . '/variants');
    $response = $controller->variants($request, new \Slim\Psr7\Response(), ['id' => $id]);
    $detail = json_decode((string)$response->getBody(), true);
    check(count($detail['variants']) === 9 && count(array_filter($detail['variants'], fn ($variant) => $variant['ready'] && str_starts_with($variant['url'], '/admin/media/images/'))) === 9, 'gallery details use authenticated URLs for protected variants');
    $preview = $controller->viewVariant($request, new \Slim\Psr7\Response(), ['id' => $id, 'variant' => 'lg', 'format' => 'jpg']);
    check($preview->getStatusCode() === 200 && $preview->getBody()->getSize() > 0 && $preview->getHeaderLine('Cache-Control') === 'private, no-store', 'admin previews stream unpublished protected images privately');
    unset($preview);
    unlink($root . '/storage/protected-media/' . $id . '_lg.jpg');
    clearstatcache();
    $response = $controller->variants($request, new \Slim\Psr7\Response(), ['id' => $id]);
    $detail = json_decode((string)$response->getBody(), true);
    $missing = array_values(array_filter($detail['variants'], fn ($variant) => $variant['name'] === 'lg.jpg'))[0];
    check(!$missing['ready'] && $missing['url'] === null, 'gallery details detect missing files despite existing database rows');

    if (function_exists('proc_open')) {
        $ready = $directory . '/ready';
        $code = '$p=new PDO($argv[1]);$p->exec("BEGIN IMMEDIATE");file_put_contents($argv[2],"1");usleep(250000);$p->exec("COMMIT");';
        $process = proc_open([PHP_BINARY, '-r', $code, 'sqlite:' . $directory . '/test.sqlite', $ready], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $deadline = microtime(true) + 5;
        while (!is_file($ready) && microtime(true) < $deadline) {
            usleep(10000);
        }
        check(is_file($ready), 'competing SQLite writer acquired a real lock');
        $pdo->exec('PRAGMA busy_timeout = 0');
        $db->execute('UPDATE albums SET title = ? WHERE id = ?', ['Retry succeeded', 987654321]);
        foreach ($pipes as $pipe) { fclose($pipe); }
        check(proc_close($process) === 0, 'SQLite write retries succeed after another process commits');
    }
    echo "Image job integration checks passed.\n";
} finally {
    foreach ($ids as $id) {
        foreach ([$root . '/public/media/', $root . '/storage/protected-media/'] as $media) {
            foreach (glob($media . $id . '_*') ?: [] as $path) { unlink($path); }
        }
        foreach (glob($root . '/storage/image-jobs/' . $id . '.*') ?: [] as $path) { unlink($path); }
    }
    foreach ($originals as $path) { @unlink($path); @unlink($path . '.hold'); }
    $settings->clearCache();
    unset($upload, $queue, $settings, $pdo, $db);
    foreach (glob($directory . '/*') ?: [] as $path) { @unlink($path); }
    @rmdir($directory);
}
