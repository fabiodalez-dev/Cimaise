<?php
declare(strict_types=1);

namespace App\Tasks;

use App\Support\PluginSignature;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'plugin:sign',
    description: 'Produce a detached Ed25519 signature (.sig) for a plugin ZIP archive'
)]
class PluginSignCommand extends Command
{
    /** Declare the `zip` argument and the `--key` / `--out` options. */
    protected function configure(): void
    {
        $this->addArgument('zip', InputArgument::REQUIRED, 'Path to the plugin .zip to sign');
        $this->addOption('key', 'k', InputOption::VALUE_REQUIRED,
            'Base64 secret key, or path to a file containing it. Defaults to the PLUGIN_SIGNING_KEY env var.');
        $this->addOption('out', 'o', InputOption::VALUE_REQUIRED,
            'Output signature path. Defaults to <zip>.sig');
    }

    /**
     * Read the target ZIP and signing key, produce a base64 detached Ed25519
     * signature, and write it to the resolved output path.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $zip = (string) $input->getArgument('zip');
        if (!is_file($zip)) {
            $output->writeln('<error>File not found: ' . $zip . '</error>');
            return Command::FAILURE;
        }

        $key = $this->resolveKey((string) ($input->getOption('key') ?? ''));
        if ($key === null) {
            $output->writeln('<error>No signing key. Pass --key or set PLUGIN_SIGNING_KEY.</error>');
            return Command::FAILURE;
        }

        $bytes = file_get_contents($zip);
        if ($bytes === false) {
            $output->writeln('<error>Cannot read ' . $zip . '</error>');
            return Command::FAILURE;
        }

        try {
            $sig = PluginSignature::sign($bytes, $key);
        } catch (\RuntimeException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $out = (string) ($input->getOption('out') ?? '') ?: $zip . '.sig';
        if (file_put_contents($out, $sig . "\n") === false) {
            $output->writeln('<error>Cannot write signature to ' . $out . '</error>');
            return Command::FAILURE;
        }

        $output->writeln('<info>Signature written to ' . $out . '</info>');
        return Command::SUCCESS;
    }

    /** Resolve the secret key from a literal base64, a file path, or the env var. */
    private function resolveKey(string $opt): ?string
    {
        if ($opt !== '') {
            return is_file($opt) ? trim((string) file_get_contents($opt)) : $opt;
        }
        $env = getenv('PLUGIN_SIGNING_KEY');
        return ($env !== false && $env !== '') ? trim($env) : null;
    }
}
