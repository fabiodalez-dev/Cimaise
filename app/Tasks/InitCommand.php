<?php
declare(strict_types=1);

namespace App\Tasks;

use App\Support\Database;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ConfirmationQuestion;

#[AsCommand(name: 'init')]
class InitCommand extends Command
{
    public function __construct(private Database $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setDescription('Initialize the Cimaise application')
             ->addOption('skip-seed', null, InputOption::VALUE_NONE, 'Skip seeding demo data')
             ->addOption('admin-email', null, InputOption::VALUE_REQUIRED, 'Admin email address')
             ->addOption('admin-password', null, InputOption::VALUE_REQUIRED, 'Admin password')
             ->addOption('base-url', null, InputOption::VALUE_REQUIRED, 'Base URL for sitemap', 'http://localhost:8000');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('');
        $output->writeln('🚀 <info>Initializing Cimaise...</info>');
        $output->writeln('');
        
        $helper = $this->getHelper('question');
        
        // Step 1: Test database connection
        $output->writeln('1️⃣  Testing database connection...');
        try {
            $this->db->pdo();
            $output->writeln('   ✅ Database connection successful');
        } catch (\Throwable $e) {
            $output->writeln('   ❌ <error>Database connection failed: ' . $e->getMessage() . '</error>');
            $output->writeln('   Please check your .env configuration');
            return Command::FAILURE;
        }
        
        // Step 2: Run migrations
        $output->writeln('');
        $output->writeln('2️⃣  Running database migrations...');
        $result = $this->runCommand('migrate', $output);
        if ($result !== 0) {
            $output->writeln('   ❌ <error>Migration failed</error>');
            return Command::FAILURE;
        }
        $output->writeln('   ✅ Migrations completed');
        
        // Step 3: Seed data (optional)
        if (!$input->getOption('skip-seed')) {
            $output->writeln('');
            $output->writeln('3️⃣  Seeding demo data...');
            $result = $this->runCommand('seed', $output);
            if ($result !== 0) {
                $output->writeln('   ⚠️  <comment>Seeding failed, but continuing...</comment>');
            } else {
                $output->writeln('   ✅ Demo data seeded');
            }
        }
        
        // Step 4: Create admin user
        $output->writeln('');
        $output->writeln('4️⃣  Creating admin user...');
        
        $adminEmail = $input->getOption('admin-email');
        if (!$adminEmail) {
            $question = new Question('   Admin email address: ');
            $question->setValidator(function ($value) {
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    throw new \RuntimeException('Please enter a valid email address');
                }
                return $value;
            });
            $adminEmail = $helper->ask($input, $output, $question);
        }
        
        $adminPassword = $input->getOption('admin-password');
        if (!$adminPassword) {
            $question = new Question('   Admin password (leave empty for auto-generated): ');
            $question->setHidden(true);
            $adminPassword = $helper->ask($input, $output, $question);
        }
        
        $result = $this->createAdmin($adminEmail, $adminPassword, $output);
        if ($result !== 0) {
            $output->writeln('   ❌ <error>Failed to create admin user</error>');
            return Command::FAILURE;
        }
        
        // Step 5: Create directories
        $output->writeln('');
        $output->writeln('5️⃣  Creating storage directories...');
        $this->createDirectories($output);
        
        // Step 6: Generate sitemap
        $output->writeln('');
        $output->writeln('6️⃣  Generating sitemap...');
        $baseUrl = $input->getOption('base-url');
        $result = $this->runSitemapCommand($baseUrl, $output);
        if ($result !== 0) {
            $output->writeln('   ⚠️  <comment>Sitemap generation failed, but continuing...</comment>');
        } else {
            $output->writeln('   ✅ Sitemap generated');
        }
        
        // Success summary
        $output->writeln('');
        $output->writeln('🎉 <info>Cimaise initialization completed successfully!</info>');
        $output->writeln('');
        $output->writeln('<comment>Next steps:</comment>');
        $output->writeln('1. Start the PHP server: <info>php -S 127.0.0.1:8000 -t public</info>');
        $output->writeln('2. Visit the frontend: <info>http://127.0.0.1:8000/</info>');
        $output->writeln('3. Access admin panel: <info>http://127.0.0.1:8000/admin/login</info>');
        $output->writeln("4. Login with: <info>{$adminEmail}</info>");
        $output->writeln('');
        $output->writeln('📚 Check <info>PREVIEW_GUIDE.md</info> for detailed instructions');
        $output->writeln('');
        
        return Command::SUCCESS;
    }
    
    private function runCommand(string $command, OutputInterface $output): int
    {
        $consolePath = dirname(__DIR__, 2) . '/bin/console';
        $cmd = "php $consolePath $command";
        
        exec($cmd, $cmdOutput, $exitCode);
        
        foreach ($cmdOutput as $line) {
            $output->writeln('   ' . $line);
        }
        
        return $exitCode;
    }
    
    private function createAdmin(string $email, ?string $password, OutputInterface $output): int
    {
        $consolePath = dirname(__DIR__, 2) . '/bin/console';
        
        if ($password) {
            $cmd = "php $consolePath user:create " . escapeshellarg($email) . " --password=" . escapeshellarg($password);
        } else {
            $cmd = "php $consolePath user:create " . escapeshellarg($email);
        }
        
        exec($cmd, $cmdOutput, $exitCode);
        
        foreach ($cmdOutput as $line) {
            $output->writeln('   ' . $line);
        }
        
        return $exitCode;
    }
    
    private function runSitemapCommand(string $baseUrl, OutputInterface $output): int
    {
        $consolePath = dirname(__DIR__, 2) . '/bin/console';
        $cmd = "php $consolePath sitemap:build --base-url=" . escapeshellarg($baseUrl);
        
        exec($cmd, $cmdOutput, $exitCode);
        
        foreach ($cmdOutput as $line) {
            $output->writeln('   ' . $line);
        }
        
        return $exitCode;
    }
    
    private function createDirectories(OutputInterface $output): void
    {
        $directories = [
            dirname(__DIR__, 2) . '/storage/originals',
            dirname(__DIR__, 2) . '/storage/tmp',
            dirname(__DIR__, 2) . '/public/media'
        ];
        
        foreach ($directories as $dir) {
            if (!is_dir($dir)) {
                if (mkdir($dir, 0755, true)) {
                    $output->writeln("   ✅ Created: $dir");
                } else {
                    $output->writeln("   ❌ <error>Failed to create: $dir</error>");
                }
            } else {
                $output->writeln("   ✅ Exists: $dir");
            }
        }
    }
}