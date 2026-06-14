<?php

declare(strict_types=1);

namespace App\Tasks;

use App\Services\UploadService;
use App\Support\Database;
use App\Support\Str;
use PDO;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Re-runnable demo seeder: wipes ONLY the albums (and their images/variants via
 * FK cascade, plus the orphaned media files) and recreates a fresh set of albums
 * filled with random stock photos. Some albums are NSFW, some are password
 * protected with the password equal to the album title.
 *
 * Stock photos come from a local directory (--stock-dir) when given, otherwise
 * they are downloaded from Lorem Picsum at run time. Nothing else in the
 * database (users, settings, categories, templates, ...) is touched.
 *
 *   php bin/console seed:demo-albums
 *   php bin/console seed:demo-albums --albums=10 --photos=10 --stock-dir=/tmp/stockpool
 */
#[AsCommand(name: 'seed:demo-albums')]
class SeedDemoAlbumsCommand extends Command
{
    /** Photography-themed titles to draw from (kept generic and safe). */
    private const TITLES = [
        'Urban Geometry', 'Coastal Light', 'Night Markets', 'Quiet Mornings',
        'Concrete & Glass', 'Faded Portraits', 'Mountain Silence', 'Neon Hours',
        'Analog Days', 'Harbour Fog', 'Desert Lines', 'Rainy Boulevards',
        'Studio Sessions', 'Wild Coast', 'Old Town Walks', 'Golden Fields',
        'Backstreet Stories', 'Winter Tones', 'Festival Crowds', 'Still Waters',
    ];

    public function __construct(private readonly Database $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Wipe only the albums and reseed them with random stock photos (incl. NSFW + password-protected)')
            ->addOption('albums', null, InputOption::VALUE_OPTIONAL, 'Number of albums to create', '10')
            ->addOption('photos', null, InputOption::VALUE_OPTIONAL, 'Photos per album', '10')
            ->addOption('nsfw', null, InputOption::VALUE_OPTIONAL, 'How many albums are NSFW', '2')
            ->addOption('password', null, InputOption::VALUE_OPTIONAL, 'How many albums are password-protected (password = title)', '2')
            ->addOption('stock-dir', null, InputOption::VALUE_OPTIONAL, 'Use local .jpg files from this directory instead of downloading')
            ->addOption('keep', null, InputOption::VALUE_NONE, 'Do not wipe existing albums first')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Skip the confirmation prompt before wiping existing albums');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $albumCount = max(1, (int) $input->getOption('albums'));
        $photosPer = max(1, (int) $input->getOption('photos'));
        $nsfwCount = max(0, (int) $input->getOption('nsfw'));
        $pwdCount = max(0, (int) $input->getOption('password'));
        $stockDir = $input->getOption('stock-dir');
        $keep = (bool) $input->getOption('keep');

        $pdo = $this->db->pdo();
        $root = dirname(__DIR__, 2);
        $upload = new UploadService($this->db);

        // --- 1. Wipe only the albums (+ their media files) ------------------
        if (!$keep) {
            // Destructive: this deletes EVERY album (and cascades to images/variants).
            // Require explicit confirmation (or --force) so it can't nuke a real site by accident.
            if (!$input->getOption('force')) {
                $question = new \Symfony\Component\Console\Question\ConfirmationQuestion(
                    '<error>This will DELETE ALL existing albums and their media files. Continue? [y/N] </error>',
                    false
                );
                /** @var \Symfony\Component\Console\Helper\QuestionHelper $helper */
                $helper = $this->getHelper('question');
                if (!$helper->ask($input, $output, $question)) {
                    $output->writeln('Aborted. (use --keep to seed without wiping, or --force to skip this prompt)');
                    return Command::SUCCESS;
                }
            }
            $output->writeln('<comment>Wiping existing albums…</comment>');
            $mediaFiles = array_merge(
                $pdo->query('SELECT path FROM image_variants')->fetchAll(PDO::FETCH_COLUMN) ?: [],
                $pdo->query('SELECT original_path FROM images')->fetchAll(PDO::FETCH_COLUMN) ?: []
            );
            $deleted = $pdo->exec('DELETE FROM albums'); // cascade: images, variants, album_tag/category, collection_images
            $this->removeMediaFiles($root, $mediaFiles);
            $output->writeln(sprintf('  removed %d albums and %d media files', (int) $deleted, count($mediaFiles)));
        }

        // --- 2. Stock photo pool -------------------------------------------
        $pool = $this->acquireStock($albumCount * $photosPer, $stockDir, $output);
        if ($pool === []) {
            $output->writeln('<error>No stock photos available (download failed and no --stock-dir).</error>');
            return Command::FAILURE;
        }
        // Assign pool photos WITHOUT replacement (shuffled + sequential pointer) so the
        // same image is never reused — two identical photos must never appear together.
        shuffle($pool);
        $poolPtr = 0;
        $poolCount = count($pool);

        $categoryIds = $pdo->query('SELECT id FROM categories')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        if (empty($categoryIds)) {
            $output->writeln('<error>No categories exist; create at least one first.</error>');
            return Command::FAILURE;
        }

        $titles = self::TITLES;
        shuffle($titles);

        // Decide per-album flags.
        $flags = [];
        for ($i = 0; $i < $albumCount; $i++) {
            $flags[] = ['nsfw' => $i < $nsfwCount, 'password' => $i >= $nsfwCount && $i < $nsfwCount + $pwdCount];
        }

        $storageDir = $root . '/storage/originals';
        if (!is_dir($storageDir)) {
            @mkdir($storageDir, 0775, true);
        }

        $created = [];
        $imgInsert = $pdo->prepare(
            'INSERT INTO images (album_id, original_path, file_hash, width, height, mime, alt_text, caption)
             VALUES (:a, :p, :h, :w, :ht, :m, :alt, :cap)'
        );

        for ($i = 0; $i < $albumCount; $i++) {
            $title = $titles[$i % count($titles)] . ($i >= count($titles) ? ' ' . $i : '');
            $slug = $this->uniqueSlug($title);
            $isNsfw = $flags[$i]['nsfw'] ? 1 : 0;
            $isPwd = $flags[$i]['password'];
            $passwordHash = $isPwd ? password_hash($title, PASSWORD_ARGON2ID) : null;
            $categoryId = (int) $categoryIds[array_rand($categoryIds)];

            // Vary the gallery form per album for visual variety, and enable the
            // frontend template switcher (otherwise the switch bar above the photos
            // stays hidden — allow_template_switch defaults to 0).
            $albumTemplateId = ($i % 7) + 1;
            $albumStmt = $pdo->prepare(
                'INSERT INTO albums (title, slug, category_id, excerpt, body, is_published, published_at,
                                     is_nsfw, password_hash, show_date, sort_order, template_id, allow_template_switch)
                 VALUES (:t, :s, :c, :e, :b, 1, :pa, :nsfw, :ph, 1, :o, :tpl, 1)'
            );
            $albumStmt->execute([
                ':t' => $title,
                ':s' => $slug,
                ':c' => $categoryId,
                ':e' => 'A curated set of ' . strtolower($title) . '.',
                ':b' => null,
                ':pa' => date('Y-m-d H:i:s'),
                ':nsfw' => $isNsfw,
                ':ph' => $passwordHash,
                ':o' => $i,
                ':tpl' => $albumTemplateId,
            ]);
            $albumId = (int) $pdo->lastInsertId();

            // Link the primary category in the junction table too (filters/category pages).
            try {
                $pdo->prepare($this->db->insertIgnoreKeyword() . ' INTO album_category (album_id, category_id) VALUES (?, ?)')
                    ->execute([$albumId, $categoryId]);
            } catch (\Throwable $e) {
                // junction optional
            }

            $coverId = null;
            for ($j = 0; $j < $photosPer; $j++) {
                // No replacement: stop adding to this album once the unique pool runs out,
                // rather than repeating an image (a smaller-but-unique album is preferable).
                if ($poolPtr >= $poolCount) {
                    break;
                }
                $srcFile = $pool[$poolPtr++];
                $hash = bin2hex(random_bytes(16));
                $dest = $storageDir . '/' . $hash . '.jpg';
                if (!@copy($srcFile, $dest)) {
                    continue;
                }
                $size = @getimagesize($dest) ?: [1600, 1067];
                $imgInsert->execute([
                    ':a' => $albumId,
                    ':p' => '/storage/originals/' . $hash . '.jpg',
                    ':h' => $hash,
                    ':w' => $size[0],
                    ':ht' => $size[1],
                    ':m' => 'image/jpeg',
                    ':alt' => $title . ' photo ' . ($j + 1),
                    ':cap' => null,
                ]);
                $imageId = (int) $pdo->lastInsertId();
                try {
                    $upload->generateVariantsForImage($imageId);
                } catch (\Throwable $e) {
                    $output->writeln('  <comment>variant gen failed for image ' . $imageId . ': ' . $e->getMessage() . '</comment>');
                }
                if ($coverId === null) {
                    $coverId = $imageId;
                }
            }
            if ($coverId !== null) {
                $pdo->prepare('UPDATE albums SET cover_image_id = ? WHERE id = ?')->execute([$coverId, $albumId]);
            }

            $tag = $isPwd ? 'password=' . $title : ($isNsfw ? 'NSFW' : 'public');
            $created[] = sprintf('  #%d  %-22s [%s]', $albumId, $title, $tag);
            $output->writeln('  seeded album: ' . $title . ' (' . $tag . ')');
        }

        // Invalidate caches: the home + gallery listings are page-cached, so
        // without this they keep linking to the wiped albums (404s) until expiry.
        $this->invalidateCaches($output);

        $output->writeln('');
        $output->writeln('<info>Done. ' . count($created) . ' albums seeded:</info>');
        foreach ($created as $line) {
            $output->writeln($line);
        }
        $output->writeln('');
        $output->writeln('<comment>Password-protected albums use their TITLE as the password.</comment>');

        return Command::SUCCESS;
    }

    private function invalidateCaches(OutputInterface $output): void
    {
        try {
            (new \App\Services\PageCacheService(new \App\Services\SettingsService($this->db), $this->db))->clearAll();
        } catch (\Throwable $e) {
            $output->writeln('  <comment>page cache clear failed: ' . $e->getMessage() . '</comment>');
        }
        try {
            \App\Services\NavigationService::invalidateCache();
        } catch (\Throwable) {
            // best-effort
        }
        // File-based caches (query cache + compiled Twig).
        foreach (['/storage/tmp/query_cache', '/storage/cache/twig'] as $sub) {
            $dir = dirname(__DIR__, 2) . $sub;
            foreach (glob($dir . '/*') ?: [] as $f) {
                if (is_file($f)) {
                    @unlink($f); // nosemgrep -- fixed cache dir, not user input
                }
            }
        }
        $output->writeln('<comment>Caches invalidated (page, navigation, query, twig).</comment>');
    }

    /**
     * @param array<int, string|null> $relPaths
     */
    private function removeMediaFiles(string $root, array $relPaths): void
    {
        $publicMedia = realpath($root . '/public/media');
        $originals = realpath($root . '/storage/originals');
        $protectedMedia = realpath($root . '/storage/protected-media');
        foreach (array_unique(array_filter($relPaths)) as $rel) {
            if (!is_string($rel)) {
                continue;
            }
            if ($rel === '') {
                continue;
            }
            if (str_contains($rel, '..')) {
                continue;
            }
            // /media/... lives under public/, /storage/... under root.
            $candidates = [
                $root . '/public' . $rel,
                $root . $rel,
                $root . '/storage/protected-media/' . basename($rel),
            ];
            foreach ($candidates as $abs) {
                $real = realpath($abs);
                if (!$real) {
                    continue;
                }
                if (!is_file($real)) {
                    continue;
                }
                $inMedia = $publicMedia && str_starts_with($real, $publicMedia . DIRECTORY_SEPARATOR);
                $inOriginals = $originals && str_starts_with($real, $originals . DIRECTORY_SEPARATOR);
                $inProtectedMedia = $protectedMedia && str_starts_with($real, $protectedMedia . DIRECTORY_SEPARATOR);
                if ($inMedia || $inOriginals || $inProtectedMedia) {
                    // $real is realpath()-resolved and confined to public/media or
                    // storage/originals; the source paths come from our own DB rows.
                    @unlink($real); // nosemgrep
                }
            }
        }
    }

    /**
     * @return string[] absolute paths to usable .jpg stock files
     */
    private function acquireStock(int $count, ?string $stockDir, OutputInterface $output): array
    {
        if (is_string($stockDir) && is_dir($stockDir)) {
            $files = glob(rtrim($stockDir, '/') . '/*.{jpg,jpeg,JPG,JPEG}', GLOB_BRACE) ?: [];
            // Dedup local files by content hash so identical photos can't both be used.
            $unique = [];
            $seen = [];
            foreach ($files as $f) {
                if (!is_file($f)) {
                    continue;
                }
                $h = md5_file($f);
                if ($h === false) {
                    continue;
                }
                if (isset($seen[$h])) {
                    continue;
                }
                $seen[$h] = true;
                $unique[] = $f;
            }
            $output->writeln('<comment>Using ' . count($unique) . ' unique local stock photos from ' . $stockDir . '</comment>');
            return $unique;
        }

        // Download ONE unique image per needed slot from Lorem Picsum (no API key).
        // Unique seeds usually yield distinct content; we additionally dedup by content
        // hash, so two identical photos can never enter the library (and therefore can
        // never render adjacent on the home or inside an album).
        $target = min(max($count, 1), 200);
        $dir = sys_get_temp_dir() . '/cimaise-stock';
        @mkdir($dir, 0775, true);
        $output->writeln('<comment>Downloading ' . $target . ' unique stock photos from Lorem Picsum…</comment>');

        $sizes = [[1200, 800], [1600, 1067], [1080, 1350], [1500, 1000], [1000, 1500]];
        $files = [];
        $seenHashes = [];
        $seed = 0;
        $maxAttempts = $target * 4;
        while (count($files) < $target && $seed < $maxAttempts) {
            $seed++;
            [$w, $h] = $sizes[$seed % count($sizes)];
            $url = 'https://picsum.photos/seed/cimaise-' . $seed . '/' . $w . '/' . $h;
            $data = $this->httpGet($url);
            if ($data === null) {
                continue;
            }
            if (strlen((string) $data) <= 5000) {
                continue;
            }
            $contentHash = md5($data);
            if (isset($seenHashes[$contentHash])) {
                continue; // identical content already downloaded — skip
            }
            $path = $dir . '/stock-' . $seed . '.jpg';
            file_put_contents($path, $data);
            if (@getimagesize($path) === false) {
                // $path is fully server-constructed ($dir + integer $seed), no user input.
                @unlink($path); // nosemgrep: path is server-controlled, not user-supplied
                continue;
            }
            $seenHashes[$contentHash] = true;
            $files[] = $path;
        }
        $output->writeln('  got ' . count($files) . ' unique stock photos');
        return $files;
    }

    private function httpGet(string $url): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $body = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return ($body !== false && $code === 200) ? (string) $body : null;
        }
        $ctx = stream_context_create(['http' => ['timeout' => 20, 'follow_location' => 1]]);
        $body = @file_get_contents($url, false, $ctx);
        return $body !== false ? $body : null;
    }

    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'album';
        $slug = $base;
        $i = 2;
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM albums WHERE slug = ?');
        while (true) {
            $stmt->execute([$slug]);
            if ($stmt->fetchColumn() === false) {
                return $slug;
            }
            $slug = $base . '-' . $i++;
        }
    }
}
