<?php
declare(strict_types=1);

namespace App\Tasks;

use App\Support\Database;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'compat:smoke', description: 'Run a quick DB-compat smoke test (tags/equipment pivots)')]
class CompatSmokeCommand extends Command
{
    public function __construct(private Database $db)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pdo = $this->db->pdo();
        $keyword = $this->db->insertIgnoreKeyword();
        $suffix = '_smoke_' . date('Ymd_His');

        $pdo->beginTransaction();
        try {
            // Ensure base rows exist
            @ $pdo->exec("PRAGMA foreign_keys = ON");

            // Create a category
            $cStmt = $pdo->prepare('INSERT INTO categories(name, slug, sort_order) VALUES(:n, :s, 0)');
            $cStmt->execute([':n' => 'Compat Cat' . $suffix, ':s' => 'compat-cat' . strtolower($suffix)]);
            $categoryId = (int)$pdo->lastInsertId();

            // Create an album
            $aStmt = $pdo->prepare('INSERT INTO albums(title, slug, category_id, is_published, sort_order) VALUES(:t, :s, :c, 0, 0)');
            $aStmt->execute([':t' => 'Compat Album' . $suffix, ':s' => 'compat-album' . strtolower($suffix), ':c' => $categoryId]);
            $albumId = (int)$pdo->lastInsertId();

            // Create lookup rows (camera/lens/film/developer/lab)
            $pdo->prepare('INSERT INTO cameras(make, model) VALUES(?, ?)')->execute(['TestMake', 'TestModel' . $suffix]);
            $cameraId = (int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO lenses(brand, model) VALUES(?, ?)')->execute(['TestBrand', 'Lens ' . $suffix]);
            $lensId = (int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO films(brand, name) VALUES(?, ?)')->execute(['TestBrand', 'Film ' . $suffix]);
            $filmId = (int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO developers(name) VALUES(?)')->execute(['Dev ' . $suffix]);
            $developerId = (int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO labs(name) VALUES(?)')->execute(['Lab ' . $suffix]);
            $labId = (int)$pdo->lastInsertId();

            // Create a tag
            $tStmt = $pdo->prepare('INSERT INTO tags(name, slug) VALUES(:n, :s)');
            $tStmt->execute([':n' => 'CompatTag' . $suffix, ':s' => 'compattag' . strtolower($suffix)]);
            $tagId = (int)$pdo->lastInsertId();

            // Test pivots with portable INSERT IGNORE/OR IGNORE
            $pdo->prepare($keyword . ' INTO album_category(album_id, category_id) VALUES(:a,:c)')->execute([':a' => $albumId, ':c' => $categoryId]);
            $pdo->prepare($keyword . ' INTO album_tag(album_id, tag_id) VALUES(:a,:t)')->execute([':a' => $albumId, ':t' => $tagId]);
            $pdo->prepare($keyword . ' INTO album_camera(album_id, camera_id) VALUES(:a,:x)')->execute([':a' => $albumId, ':x' => $cameraId]);
            $pdo->prepare($keyword . ' INTO album_lens(album_id, lens_id) VALUES(:a,:x)')->execute([':a' => $albumId, ':x' => $lensId]);
            $pdo->prepare($keyword . ' INTO album_film(album_id, film_id) VALUES(:a,:x)')->execute([':a' => $albumId, ':x' => $filmId]);
            $pdo->prepare($keyword . ' INTO album_developer(album_id, developer_id) VALUES(:a,:x)')->execute([':a' => $albumId, ':x' => $developerId]);
            $pdo->prepare($keyword . ' INTO album_lab(album_id, lab_id) VALUES(:a,:x)')->execute([':a' => $albumId, ':x' => $labId]);

            $pdo->commit();
            $output->writeln('<info>Smoke OK</info>: pivots inserted with keyword [' . $keyword . ']');
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $output->writeln('<error>Smoke failed:</error> ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}

