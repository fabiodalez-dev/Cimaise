<?php
declare(strict_types=1);

namespace App\Tasks;

use App\Support\Database;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

#[AsCommand(name: 'user:create', description: 'Create an admin user and print the password')]
class UserCreateCommand extends Command
{
    public function __construct(private Database $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Admin email');
        $this->addArgument('password', InputArgument::OPTIONAL, 'Password (if omitted, generated)');
        $this->addOption('reset', null, \Symfony\Component\Console\Input\InputOption::VALUE_NONE, 'Reset password if user exists');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = trim((string)$input->getArgument('email'));
        $password = (string)($input->getArgument('password') ?? '');
        if ($password === '') {
            $password = bin2hex(random_bytes(6)); // 12 hex chars
        }

        $pdo = $this->db->pdo();
        $exists = $pdo->prepare('SELECT id FROM users WHERE email=:e LIMIT 1');
        $exists->execute([':e'=>$email]);
        $row = $exists->fetch();
        $hash = password_hash($password, PASSWORD_ARGON2ID);
        if ($row) {
            $reset = (bool)$input->getOption('reset');
            if (!$reset) {
                $output->writeln('<comment>User already exists:</comment> ' . $email . ' (use --reset to update password)');
                return Command::SUCCESS;
            }
            $upd = $pdo->prepare('UPDATE users SET password_hash=:h WHERE id=:id');
            $upd->execute([':h'=>$hash, ':id'=>(int)$row['id']]);
            $output->writeln('<info>Password updated for:</info> ' . $email);
            $output->writeln('<comment>New Password:</comment> ' . $password);
            return Command::SUCCESS;
        } else {
            $sql = 'INSERT INTO users (email, password_hash, role) VALUES (:email, :hash, \'admin\')';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':email' => $email, ':hash' => $hash]);
            $output->writeln('<info>Admin created:</info> ' . $email);
            $output->writeln('<comment>Password:</comment> ' . $password);
        }
        return Command::SUCCESS;
    }
}
