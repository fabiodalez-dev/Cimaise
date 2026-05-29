<?php
declare(strict_types=1);

namespace App\Tasks;

use App\Support\PluginSignature;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'plugin:keygen',
    description: 'Generate the Ed25519 keypair used to sign/verify plugin archives'
)]
class PluginKeygenCommand extends Command
{
    /** Declare the `--force` option used to overwrite an existing public key. */
    protected function configure(): void
    {
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite an existing public key');
    }

    /**
     * Generate an Ed25519 keypair, write the public key to the resources path,
     * and print the secret key to stdout for the operator to store securely.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $pubPath = PluginSignature::publicKeyPath();

        if (is_file($pubPath) && trim((string) file_get_contents($pubPath)) !== '' && !$input->getOption('force')) {
            $output->writeln('<error>A public key already exists at ' . $pubPath . '</error>');
            $output->writeln('Re-run with --force to overwrite (this invalidates every previously signed plugin).');
            return Command::FAILURE;
        }

        $pair = PluginSignature::generateKeypair();

        if (!is_dir(dirname($pubPath)) && !mkdir(dirname($pubPath), 0755, true) && !is_dir(dirname($pubPath))) {
            $output->writeln('<error>Cannot create directory ' . dirname($pubPath) . '</error>');
            return Command::FAILURE;
        }
        if (file_put_contents($pubPath, $pair['public'] . "\n") === false) {
            $output->writeln('<error>Cannot write public key to ' . $pubPath . '</error>');
            return Command::FAILURE;
        }

        $output->writeln('<info>Public key written to ' . $pubPath . '</info>');
        $output->writeln('<comment>Commit that file. Plugin signature verification is now ENFORCED on upload.</comment>');
        $output->writeln('');
        $output->writeln('<comment>SECRET KEY (store securely — NEVER commit it):</comment>');
        $output->writeln('');
        $output->writeln('  ' . $pair['secret']);
        $output->writeln('');
        $output->writeln('Use it as the PLUGIN_SIGNING_KEY env var for `plugin:sign` and in CI.');

        return Command::SUCCESS;
    }
}
