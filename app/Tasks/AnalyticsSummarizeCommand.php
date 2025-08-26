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

#[AsCommand(name: 'analytics:summarize', description: 'Generate daily analytics summaries for improved performance')]
class AnalyticsSummarizeCommand extends Command
{
    public function __construct(private Database $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Generate daily analytics summaries for improved performance')
            ->setHelp('This command pre-computes daily statistics to improve dashboard loading times.')
            ->addOption(
                'date',
                'd',
                InputOption::VALUE_OPTIONAL,
                'Generate summary for specific date (YYYY-MM-DD)',
                null
            )
            ->addOption(
                'days',
                null,
                InputOption::VALUE_OPTIONAL,
                'Generate summaries for the last N days',
                '7'
            )
            ->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Regenerate existing summaries'
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
                $io->warning('Analytics is currently disabled. No summaries to generate.');
                return Command::SUCCESS;
            }
            
            $specificDate = $input->getOption('date');
            $days = (int)$input->getOption('days');
            $force = $input->getOption('force');
            
            $io->title('Analytics Daily Summary Generation');
            
            // Determine dates to process
            $datesToProcess = [];
            
            if ($specificDate) {
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $specificDate)) {
                    $io->error('Invalid date format. Use YYYY-MM-DD.');
                    return Command::FAILURE;
                }
                $datesToProcess[] = $specificDate;
            } else {
                // Generate for the last N days
                for ($i = 0; $i < $days; $i++) {
                    $datesToProcess[] = date('Y-m-d', strtotime("-{$i} days"));
                }
            }
            
            $io->text("Processing " . count($datesToProcess) . " date(s)...");
            
            $generated = 0;
            $skipped = 0;
            
            foreach ($datesToProcess as $date) {
                // Check if summary already exists
                if (!$force && $this->summaryExists($pdo, $date)) {
                    $io->text("Summary for {$date} already exists (use --force to regenerate)");
                    $skipped++;
                    continue;
                }
                
                $io->text("Generating summary for {$date}...");
                $this->generateDailySummary($pdo, $date);
                $generated++;
            }
            
            $io->success("Summary generation completed! Generated: {$generated}, Skipped: {$skipped}");
            
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            $io->error('Summary generation failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
    
    private function summaryExists(\PDO $pdo, string $date): bool
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM analytics_daily_summary WHERE date = ?');
        $stmt->execute([$date]);
        return (int)$stmt->fetchColumn() > 0;
    }
    
    private function generateDailySummary(\PDO $pdo, string $date): void
    {
        // Get basic stats for the date
        $stmt = $pdo->prepare('
            SELECT 
                COUNT(DISTINCT session_id) as total_sessions,
                COUNT(*) as total_pageviews,
                COUNT(DISTINCT 
                    CASE WHEN is_bot = 0 THEN session_id END
                ) as unique_visitors,
                AVG(duration) as avg_session_duration
            FROM analytics_sessions s
            LEFT JOIN analytics_pageviews p ON s.session_id = p.session_id
            WHERE DATE(s.started_at) = ?
        ');
        $stmt->execute([$date]);
        $stats = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        // Calculate bounce rate (sessions with only 1 page view)
        $stmt = $pdo->prepare('
            SELECT COUNT(*) as bounce_sessions
            FROM analytics_sessions 
            WHERE DATE(started_at) = ? AND page_views <= 1 AND is_bot = 0
        ');
        $stmt->execute([$date]);
        $bounceCount = (int)$stmt->fetchColumn();
        $bounceRate = $stats['unique_visitors'] > 0 ? ($bounceCount / $stats['unique_visitors']) * 100 : 0;
        
        // Get top pages
        $stmt = $pdo->prepare('
            SELECT page_url, page_title, COUNT(*) as views
            FROM analytics_pageviews p
            JOIN analytics_sessions s ON p.session_id = s.session_id
            WHERE DATE(p.viewed_at) = ? AND s.is_bot = 0
            GROUP BY page_url
            ORDER BY views DESC
            LIMIT 10
        ');
        $stmt->execute([$date]);
        $topPages = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        // Get top countries
        $stmt = $pdo->prepare('
            SELECT country_code, COUNT(*) as sessions
            FROM analytics_sessions
            WHERE DATE(started_at) = ? AND is_bot = 0 AND country_code IS NOT NULL
            GROUP BY country_code
            ORDER BY sessions DESC
            LIMIT 10
        ');
        $stmt->execute([$date]);
        $topCountries = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        // Get top browsers
        $stmt = $pdo->prepare('
            SELECT browser, COUNT(*) as sessions
            FROM analytics_sessions
            WHERE DATE(started_at) = ? AND is_bot = 0 AND browser IS NOT NULL
            GROUP BY browser
            ORDER BY sessions DESC
            LIMIT 10
        ');
        $stmt->execute([$date]);
        $topBrowsers = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        // Get top albums
        $stmt = $pdo->prepare('
            SELECT p.album_id, a.title, COUNT(*) as views
            FROM analytics_pageviews p
            JOIN analytics_sessions s ON p.session_id = s.session_id
            LEFT JOIN albums a ON p.album_id = a.id
            WHERE DATE(p.viewed_at) = ? AND s.is_bot = 0 AND p.album_id IS NOT NULL
            GROUP BY p.album_id
            ORDER BY views DESC
            LIMIT 10
        ');
        $stmt->execute([$date]);
        $topAlbums = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        
        // Insert or update summary
        $stmt = $pdo->prepare('
            INSERT OR REPLACE INTO analytics_daily_summary (
                date, total_sessions, total_pageviews, unique_visitors, bounce_rate,
                avg_session_duration, top_pages, top_countries, top_browsers, top_albums
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        
        $stmt->execute([
            $date,
            $stats['total_sessions'] ?: 0,
            $stats['total_pageviews'] ?: 0,
            $stats['unique_visitors'] ?: 0,
            round($bounceRate, 2),
            round($stats['avg_session_duration'] ?: 0),
            json_encode($topPages),
            json_encode($topCountries),
            json_encode($topBrowsers),
            json_encode($topAlbums)
        ]);
    }
}