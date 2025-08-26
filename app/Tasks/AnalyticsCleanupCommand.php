<?php
declare(strict_types=1);

namespace App\Tasks;

use App\Services\AnalyticsService;
use App\Support\Database;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'analytics:cleanup', description: 'Clean up old analytics data based on retention settings')]
class AnalyticsCleanupCommand extends Command
{
    public function __construct(private Database $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Clean up old analytics data based on retention settings')
            ->setHelp('This command removes analytics data older than the configured retention period.')
            ->addOption(
                'days',
                'd',
                InputOption::VALUE_OPTIONAL,
                'Override retention days (default: use settings)',
                null
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Show what would be deleted without actually deleting'
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Force cleanup without confirmation'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        
        try {
            $pdo = $this->db->pdo();
            $analytics = new AnalyticsService($pdo);
            
            // Check if analytics is enabled
            if (!$analytics->isEnabled()) {
                $io->warning('Analytics is currently disabled. No cleanup needed.');
                return Command::SUCCESS;
            }
            
            // Get retention days
            $overrideDays = $input->getOption('days');
            $retentionDays = $overrideDays ? (int)$overrideDays : $analytics->getSetting('data_retention_days', 365);
            
            if ($retentionDays <= 0) {
                $io->info('Data retention is set to "never delete". No cleanup needed.');
                return Command::SUCCESS;
            }
            
            $isDryRun = $input->getOption('dry-run');
            $isForced = $input->getOption('force');
            
            $io->title('Analytics Data Cleanup');
            $io->text([
                "Data retention period: {$retentionDays} days",
                "Mode: " . ($isDryRun ? 'DRY RUN (preview only)' : 'LIVE CLEANUP'),
            ]);
            
            // Calculate cutoff date
            $cutoffDate = date('Y-m-d H:i:s', strtotime("-{$retentionDays} days"));
            $io->text("Will delete data older than: {$cutoffDate}");
            
            // Count records to be deleted
            $counts = $this->getRecordCounts($pdo, $cutoffDate);
            
            if (array_sum($counts) === 0) {
                $io->success('No old data found. Nothing to clean up.');
                return Command::SUCCESS;
            }
            
            // Show what will be deleted
            $io->section('Records to be deleted:');
            $io->table(
                ['Table', 'Records'],
                [
                    ['Sessions', number_format($counts['sessions'])],
                    ['Page Views', number_format($counts['pageviews'])],
                    ['Events', number_format($counts['events'])],
                    ['Daily Summaries', number_format($counts['summaries'])],
                    ['TOTAL', number_format(array_sum($counts))]
                ]
            );
            
            if ($isDryRun) {
                $io->note('This was a dry run. No data was actually deleted.');
                return Command::SUCCESS;
            }
            
            // Confirm deletion
            if (!$isForced && !$io->confirm('Are you sure you want to delete this data? This action cannot be undone.', false)) {
                $io->info('Cleanup cancelled.');
                return Command::SUCCESS;
            }
            
            // Perform cleanup
            $io->section('Performing cleanup...');
            $deletedCounts = $this->performCleanup($pdo, $cutoffDate, $io);
            
            $io->success('Cleanup completed successfully!');
            $io->table(
                ['Table', 'Deleted Records'],
                [
                    ['Sessions', number_format($deletedCounts['sessions'])],
                    ['Page Views', number_format($deletedCounts['pageviews'])],
                    ['Events', number_format($deletedCounts['events'])],
                    ['Daily Summaries', number_format($deletedCounts['summaries'])],
                    ['TOTAL', number_format(array_sum($deletedCounts))]
                ]
            );
            
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $io->error('Cleanup failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
    
    private function getRecordCounts(\PDO $pdo, string $cutoffDate): array
    {
        $counts = [];
        
        // Count sessions
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM analytics_sessions WHERE started_at < ?');
        $stmt->execute([$cutoffDate]);
        $counts['sessions'] = (int)$stmt->fetchColumn();
        
        // Count pageviews
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM analytics_pageviews WHERE viewed_at < ?');
        $stmt->execute([$cutoffDate]);
        $counts['pageviews'] = (int)$stmt->fetchColumn();
        
        // Count events
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM analytics_events WHERE occurred_at < ?');
        $stmt->execute([$cutoffDate]);
        $counts['events'] = (int)$stmt->fetchColumn();
        
        // Count daily summaries
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM analytics_daily_summary WHERE date < DATE(?)');
        $stmt->execute([$cutoffDate]);
        $counts['summaries'] = (int)$stmt->fetchColumn();
        
        return $counts;
    }
    
    private function performCleanup(\PDO $pdo, string $cutoffDate, SymfonyStyle $io): array
    {
        $deletedCounts = [];
        
        $pdo->beginTransaction();
        
        try {
            // Delete in reverse dependency order to avoid foreign key issues
            
            // 1. Delete events first
            $io->text('Deleting old events...');
            $stmt = $pdo->prepare('DELETE FROM analytics_events WHERE occurred_at < ?');
            $stmt->execute([$cutoffDate]);
            $deletedCounts['events'] = $stmt->rowCount();
            
            // 2. Delete pageviews
            $io->text('Deleting old page views...');
            $stmt = $pdo->prepare('DELETE FROM analytics_pageviews WHERE viewed_at < ?');
            $stmt->execute([$cutoffDate]);
            $deletedCounts['pageviews'] = $stmt->rowCount();
            
            // 3. Delete sessions (this will cascade to remaining related data)
            $io->text('Deleting old sessions...');
            $stmt = $pdo->prepare('DELETE FROM analytics_sessions WHERE started_at < ?');
            $stmt->execute([$cutoffDate]);
            $deletedCounts['sessions'] = $stmt->rowCount();
            
            // 4. Delete daily summaries
            $io->text('Deleting old daily summaries...');
            $stmt = $pdo->prepare('DELETE FROM analytics_daily_summary WHERE date < DATE(?)');
            $stmt->execute([$cutoffDate]);
            $deletedCounts['summaries'] = $stmt->rowCount();
            
            $pdo->commit();
            
            // Optimize tables after cleanup (SQLite specific)
            if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $io->text('Optimizing database...');
                $pdo->exec('VACUUM');
            }
            
        } catch (\Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
        
        return $deletedCounts;
    }
}