<?php
declare(strict_types=1);

namespace App\Tasks;

use App\Support\Database;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'media:normalize-paths', description: 'Normalize image_variants.path from /public/media/... to /media/...')]
class NormalizeMediaPathsCommand extends Command
{
    public function __construct(private Database $db)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pdo = $this->db->pdo();
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM image_variants WHERE path LIKE '/public/media/%'");
        $countStmt->execute();
        $count = (int)$countStmt->fetchColumn();
        if ($count === 0) {
            $output->writeln('<info>No legacy paths to normalize.</info>');
            return Command::SUCCESS;
        }

        $output->writeln('<comment>Normalizing ' . $count . ' rows...</comment>');
        $upd = $pdo->prepare("UPDATE image_variants SET path = REPLACE(path, '/public', '') WHERE path LIKE '/public/media/%'");
        $upd->execute();
        $output->writeln('<info>Done.</info>');
        return Command::SUCCESS;
    }
}

