<?php
declare(strict_types=1);

namespace App\Tasks;

use App\Installer\Installer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'install', description: 'Install Cimaise application')]
class InstallCommand extends Command
{
    private string $rootPath;
    
    public function __construct()
    {
        $this->rootPath = dirname(__DIR__, 2);
        parent::__construct();
    }
    
    protected function configure(): void
    {
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Force installation even if already installed');
    }
    
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $installer = new Installer($this->rootPath);
        
        // Check if already installed
        if ($installer->isInstalled() && !$input->getOption('force')) {
            $io->warning('Cimaise is already installed. Use --force to reinstall.');
            return Command::FAILURE;
        }
        
        $io->title('Cimaise Installer');
        
        // Verify requirements
        $io->section('Checking Requirements');
        $requirements = $this->checkRequirements();
        
        if (!empty($requirements['errors'])) {
            $io->error('Requirements check failed:');
            foreach ($requirements['errors'] as $error) {
                $io->writeln("  - {$error}");
            }
            return Command::FAILURE;
        }
        
        $io->success('All requirements met');
        
        // Collect installation data
        $data = $this->collectInstallationData($input, $output, $io);
        
        if (empty($data)) {
            $io->error('Installation cancelled');
            return Command::FAILURE;
        }
        
        // Confirm installation
        $io->section('Installation Summary');
        $this->showInstallationSummary($io, $data);
        
        $helper = $this->getHelper('question');
        $question = new ConfirmationQuestion('Do you want to proceed with the installation? (y/N) ', false);
        
        if (!$helper->ask($input, $output, $question)) {
            $io->warning('Installation cancelled');
            return Command::FAILURE;
        }
        
        // Run installation
        $io->section('Installing Cimaise');
        
        try {
            $installer->install($data);
            $io->success('Cimaise installed successfully!');
            
            $io->note('Next steps:');
            $io->writeln([
                '1. Start the development server: php -S localhost:8000 -t public public/router.php',
                '2. Visit http://localhost:8000/admin/login',
                '3. Log in with your admin credentials',
                '4. Start creating your photography portfolio!'
            ]);
            
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error("Installation failed: {$e->getMessage()}");
            return Command::FAILURE;
        }
    }
    
    private function checkRequirements(): array
    {
        $errors = [];
        
        // Check PHP version
        if (version_compare(PHP_VERSION, '8.2.0', '<')) {
            $errors[] = 'PHP 8.2 or higher is required. Current version: ' . PHP_VERSION;
        }
        
        // Check required extensions
        $requiredExtensions = ['pdo', 'gd', 'mbstring', 'openssl'];
        foreach ($requiredExtensions as $ext) {
            if (!extension_loaded($ext)) {
                $errors[] = "Required PHP extension '{$ext}' is not installed";
            }
        }
        
        // Check if either PDO MySQL or PDO SQLite is available
        if (!extension_loaded('pdo_mysql') && !extension_loaded('pdo_sqlite')) {
            $errors[] = 'Either PDO MySQL or PDO SQLite extension is required';
        }
        
        // Check write permissions
        $writablePaths = [
            $this->rootPath . '/.env',
            $this->rootPath . '/database',
            $this->rootPath . '/storage',
            $this->rootPath . '/public/media'
        ];
        
        foreach ($writablePaths as $path) {
            if (!is_writable(dirname($path))) {
                $errors[] = "Directory '" . dirname($path) . "' is not writable";
            }
        }
        
        return ['errors' => $errors];
    }
    
    private function collectInstallationData(InputInterface $input, OutputInterface $output, SymfonyStyle $io): array
    {
        $helper = $this->getHelper('question');
        $data = [];
        
        // Database configuration
        $io->section('Database Configuration');
        
        $question = new Question('Database type (sqlite/mysql) [sqlite]: ', 'sqlite');
        $data['db_connection'] = $helper->ask($input, $output, $question);
        
        if ($data['db_connection'] === 'mysql') {
            $question = new Question('MySQL Host [127.0.0.1]: ', '127.0.0.1');
            $data['db_host'] = $helper->ask($input, $output, $question);
            
            $question = new Question('MySQL Port [3306]: ', '3306');
            $data['db_port'] = $helper->ask($input, $output, $question);
            
            $question = new Question('Database name [cimaise]: ', 'cimaise');
            $data['db_database'] = $helper->ask($input, $output, $question);
            
            $question = new Question('MySQL Username [root]: ', 'root');
            $data['db_username'] = $helper->ask($input, $output, $question);
            
            $question = new Question('MySQL Password (leave empty if none): ', '');
            $data['db_password'] = $helper->ask($input, $output, $question);
            
            $question = new Question('Charset [utf8mb4]: ', 'utf8mb4');
            $data['db_charset'] = $helper->ask($input, $output, $question);
            
            $question = new Question('Collation [utf8mb4_unicode_ci]: ', 'utf8mb4_unicode_ci');
            $data['db_collation'] = $helper->ask($input, $output, $question);
        } else {
            $defaultDbPath = $this->rootPath . '/database/database.sqlite';
            $question = new Question("SQLite database path [{$defaultDbPath}]: ", $defaultDbPath);
            $data['db_database'] = $helper->ask($input, $output, $question);
        }
        
        // Admin user configuration
        $io->section('Admin User');
        
        $question = new Question('Admin name: ');
        $data['admin_name'] = $helper->ask($input, $output, $question);
        
        $question = new Question('Admin email: ');
        $data['admin_email'] = $helper->ask($input, $output, $question);
        
        $question = new Question('Admin password (min 8 characters): ');
        $question->setHidden(true);
        $question->setHiddenFallback(false);
        $data['admin_password'] = $helper->ask($input, $output, $question);
        
        // Site settings
        $io->section('Site Settings');
        
        $question = new Question('Site title [Cimaise]: ', 'Cimaise');
        $data['site_title'] = $helper->ask($input, $output, $question);
        
        $question = new Question('Site description [Professional Photography Portfolio]: ', 'Professional Photography Portfolio');
        $data['site_description'] = $helper->ask($input, $output, $question);
        
        $question = new Question('Copyright notice [© {year} Photography Portfolio]: ', '© {year} Photography Portfolio');
        $data['site_copyright'] = $helper->ask($input, $output, $question);
        
        $question = new Question('Contact email (optional): ', '');
        $data['site_email'] = $helper->ask($input, $output, $question);
        
        // Application URL
        $question = new Question('Application URL [http://localhost:8000]: ', 'http://localhost:8000');
        $data['app_url'] = $helper->ask($input, $output, $question);
        
        return $data;
    }
    
    private function showInstallationSummary(SymfonyStyle $io, array $data): void
    {
        $io->writeln('<info>Database:</info>');
        $io->writeln("  Type: {$data['db_connection']}");
        
        if ($data['db_connection'] === 'mysql') {
            $io->writeln("  Host: {$data['db_host']}:{$data['db_port']}");
            $io->writeln("  Database: {$data['db_database']}");
            $io->writeln("  Username: {$data['db_username']}");
        } else {
            $io->writeln("  Path: {$data['db_database']}");
        }
        
        $io->writeln('');
        $io->writeln('<info>Admin User:</info>');
        $io->writeln("  Name: {$data['admin_name']}");
        $io->writeln("  Email: {$data['admin_email']}");
        
        $io->writeln('');
        $io->writeln('<info>Site Settings:</info>');
        $io->writeln("  Title: {$data['site_title']}");
        $io->writeln("  Description: {$data['site_description']}");
        $io->writeln("  Copyright: {$data['site_copyright']}");
        $io->writeln("  Email: " . ($data['site_email'] ?: 'Not set'));
        $io->writeln("  URL: {$data['app_url']}");
    }
}