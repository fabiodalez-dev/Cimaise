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

/**
 * Produce a detached, domain-separated Ed25519 signature (see PluginSignature).
 *
 * The --type decides WHAT the signature vouches for, and a signature of one
 * type never verifies as another:
 *   plugin  (default) a plugin ZIP uploaded through Admin → Plugins
 *   patch   a release migration patch (pre-update-patch.php / post-install-patch.php),
 *           bound to its file name and --release
 *   package the self-updater's release package cimaise-vX.Y.Z.zip, bound to --release
 *
 * The release workflow signs packages and patches with bin/release-sign.php
 * (same framing, no Composer dependencies); this command is the maintainer's
 * local equivalent.
 */
#[AsCommand(
    name: 'plugin:sign',
    description: 'Produce a detached Ed25519 signature (.sig) for a plugin ZIP, a release patch or a release package'
)]
class PluginSignCommand extends Command
{
    /** Declare the `zip` argument and the `--key` / `--out` / `--type` / `--release` options. */
    protected function configure(): void
    {
        $this->addArgument('zip', InputArgument::REQUIRED, 'Path to the file to sign');
        $this->addOption(
            'key',
            'k',
            InputOption::VALUE_REQUIRED,
            'Base64 secret key, or path to a file containing it. Defaults to the PLUGIN_SIGNING_KEY env var.'
        );
        $this->addOption(
            'out',
            'o',
            InputOption::VALUE_REQUIRED,
            'Output signature path. Defaults to <file>.sig'
        );
        $this->addOption(
            'type',
            't',
            InputOption::VALUE_REQUIRED,
            'What the signature is for: plugin, patch or package',
            PluginSignature::PURPOSE_PLUGIN
        );
        $this->addOption(
            'release',
            'r',
            InputOption::VALUE_REQUIRED,
            'Release version the patch/package belongs to (e.g. 1.5.0). Required for --type=patch|package.'
        );
    }

    /**
     * Read the target file and signing key, produce a base64 detached Ed25519
     * signature over the framed message for --type, and write it out.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = (string) $input->getArgument('zip');
        if (!is_file($file)) {
            $output->writeln('<error>File not found: ' . $file . '</error>');
            return Command::FAILURE;
        }

        $type = (string) $input->getOption('type');
        $release = trim((string) ($input->getOption('release') ?? ''));
        if (!in_array($type, [PluginSignature::PURPOSE_PLUGIN, PluginSignature::PURPOSE_PATCH, PluginSignature::PURPOSE_PACKAGE], true)) {
            $output->writeln('<error>Unknown --type "' . $type . '" (expected plugin, patch or package)</error>');
            return Command::FAILURE;
        }
        if ($type !== PluginSignature::PURPOSE_PLUGIN && $release === '') {
            $output->writeln('<error>--release is required for --type=' . $type . '</error>');
            return Command::FAILURE;
        }

        $key = $this->resolveKey((string) ($input->getOption('key') ?? ''));
        if ($key === null) {
            $output->writeln('<error>No signing key. Pass --key or set PLUGIN_SIGNING_KEY.</error>');
            return Command::FAILURE;
        }

        $bytes = file_get_contents($file);
        if ($bytes === false) {
            $output->writeln('<error>Cannot read ' . $file . '</error>');
            return Command::FAILURE;
        }

        try {
            $sig = match ($type) {
                PluginSignature::PURPOSE_PATCH => PluginSignature::signPatch(basename($file), $release, $bytes, $key),
                PluginSignature::PURPOSE_PACKAGE => PluginSignature::signPackage($release, hash('sha256', $bytes), $key),
                default => PluginSignature::signPlugin($bytes, $key),
            };
        } catch (\RuntimeException | \InvalidArgumentException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $out = (string) ($input->getOption('out') ?? '') ?: $file . '.sig';
        if (file_put_contents($out, $sig . "\n") === false) {
            $output->writeln('<error>Cannot write signature to ' . $out . '</error>');
            return Command::FAILURE;
        }

        $output->writeln('<info>' . ucfirst($type) . ' signature written to ' . $out . '</info>');
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
