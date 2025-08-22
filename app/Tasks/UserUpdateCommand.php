<?php
declare(strict_types=1);

namespace App\Tasks;

use App\Support\Database;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

#[AsCommand(name: 'user:update', description: 'Interactively update admin email and/or password')]
class UserUpdateCommand extends Command
{
    public function __construct(private Database $db)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::OPTIONAL, 'Current admin email');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');

        $pdo = $this->db->pdo();
        $email = (string)($input->getArgument('email') ?? '');
        if ($email === '') {
            $q = new Question('Current admin email: ');
            $email = (string)$helper->ask($input, $output, $q);
        }
        $stmt = $pdo->prepare('SELECT id, email FROM users WHERE email = :e LIMIT 1');
        $stmt->execute([':e' => $email]);
        $user = $stmt->fetch();
        if (!$user) {
            $output->writeln('<error>User not found: ' . $email . '</error>');
            return Command::FAILURE;
        }

        $qNewEmail = new Question('New email (leave blank to keep): ', (string)$user['email']);
        $newEmail = (string)$helper->ask($input, $output, $qNewEmail);

        $qPass1 = new Question('New password (leave blank to keep): ');
        $qPass1->setHidden(true)->setHiddenFallback(false);
        $pass1 = (string)$helper->ask($input, $output, $qPass1);
        $pass2 = '';
        if ($pass1 !== '') {
            $qPass2 = new Question('Repeat new password: ');
            $qPass2->setHidden(true)->setHiddenFallback(false);
            $pass2 = (string)$helper->ask($input, $output, $qPass2);
            if ($pass1 !== $pass2) {
                $output->writeln('<error>Passwords do not match.</error>');
                return Command::FAILURE;
            }
        }

        $pdo->beginTransaction();
        try {
            if ($newEmail !== '' && $newEmail !== $user['email']) {
                // check duplicate
                $chk = $pdo->prepare('SELECT 1 FROM users WHERE email=:e LIMIT 1');
                $chk->execute([':e' => $newEmail]);
                if ($chk->fetchColumn()) {
                    $output->writeln('<error>Email already in use: ' . $newEmail . '</error>');
                    $pdo->rollBack();
                    return Command::FAILURE;
                }
                $upd = $pdo->prepare('UPDATE users SET email=:e WHERE id=:id');
                $upd->execute([':e' => $newEmail, ':id' => (int)$user['id']]);
            }
            if ($pass1 !== '') {
                $hash = password_hash($pass1, PASSWORD_ARGON2ID);
                $upd = $pdo->prepare('UPDATE users SET password_hash=:h WHERE id=:id');
                $upd->execute([':h' => $hash, ':id' => (int)$user['id']]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $output->writeln('<error>Failed to update user: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $output->writeln('<info>User updated.</info>');
        if ($newEmail !== '' && $newEmail !== $user['email']) {
            $output->writeln('New email: ' . $newEmail);
        }
        if ($pass1 !== '') {
            $output->writeln('<comment>Password changed.</comment>');
        }
        return Command::SUCCESS;
    }
}

