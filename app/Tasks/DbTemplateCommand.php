<?php

declare(strict_types=1);

namespace App\Tasks;

use PDO;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Keep database/template.sqlite in sync with database/schema.sqlite.sql.
 *
 * schema.sqlite.sql is the single source of truth; template.sqlite is only an
 * optional pre-built fast-path artifact. This command (re)generates it, and with
 * --check verifies it has not drifted from the schema (for CI / release builds),
 * so it can never be committed stale (the bug that left collections/* out of fresh
 * SQLite installs).
 */
#[AsCommand(
    name: 'db:template',
    description: 'Regenerate (or --check) database/template.sqlite from schema.sqlite.sql'
)]
class DbTemplateCommand extends Command
{
    private string $schemaPath;
    private string $templatePath;

    public function __construct()
    {
        parent::__construct();
        $root = dirname(__DIR__, 2);
        $this->schemaPath = $root . '/database/schema.sqlite.sql';
        $this->templatePath = $root . '/database/template.sqlite';
    }

    protected function configure(): void
    {
        $this->addOption(
            'check',
            null,
            InputOption::VALUE_NONE,
            'Verify template.sqlite matches the schema instead of regenerating; exit 1 if it drifted'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!is_file($this->schemaPath)) {
            $output->writeln('<error>Schema not found: ' . $this->schemaPath . '</error>');
            return Command::FAILURE;
        }
        $sql = file_get_contents($this->schemaPath);
        if ($sql === false || trim($sql) === '') {
            $output->writeln('<error>Could not read schema: ' . $this->schemaPath . '</error>');
            return Command::FAILURE;
        }

        // Build a fresh database from the schema in a throwaway temp file.
        $tmp = (string) tempnam(sys_get_temp_dir(), 'cimaise_tpl_');
        @unlink($tmp); // PDO recreates it; we only needed a unique name
        try {
            $fresh = $this->buildFrom($sql, $tmp);
            $freshShape = $this->shape($fresh);
            $fresh = null; // close handle before any copy

            if ($input->getOption('check')) {
                if (!is_file($this->templatePath)) {
                    $output->writeln('<error>template.sqlite is missing — run `db:template` to generate it.</error>');
                    return Command::FAILURE;
                }
                $current = new PDO('sqlite:' . $this->templatePath);
                $current->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $currentShape = $this->shape($current);
                $current = null;

                $diff = $this->diff($freshShape, $currentShape);
                if ($diff === []) {
                    $output->writeln('<info>template.sqlite is in sync with schema.sqlite.sql.</info>');
                    return Command::SUCCESS;
                }
                $output->writeln('<error>template.sqlite has drifted from schema.sqlite.sql:</error>');
                foreach ($diff as $line) {
                    $output->writeln('  - ' . $line);
                }
                $output->writeln('Run `php bin/console db:template` to regenerate it.');
                return Command::FAILURE;
            }

            // Regenerate: rebuild straight into the template path.
            @unlink($this->templatePath);
            $this->buildFrom($sql, $this->templatePath);
            $tableCount = count(array_filter(
                array_keys($freshShape['objects']),
                static fn (string $k): bool => str_starts_with($k, 'table ')
            ));
            $output->writeln(sprintf(
                '<info>Regenerated template.sqlite</info> (%d tables, %d settings).',
                $tableCount,
                count($freshShape['settings'])
            ));
            return Command::SUCCESS;
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /** Execute the full schema script into a new SQLite file and return the PDO handle. */
    private function buildFrom(string $sql, string $path): PDO
    {
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec($sql); // SQLite runs a full multi-statement script in one call
        return $pdo;
    }

    /**
     * Logical fingerprint of a DB. Binary comparison is useless (SQLite files differ
     * byte-wise), so we compare what actually matters for a CI/release guard:
     *  - every schema OBJECT's normalized DDL (tables incl. columns/constraints,
     *    indexes, triggers, views) from sqlite_master.sql — catches column/index/
     *    constraint drift, not just renamed tables;
     *  - the seeded settings as key => value — catches changed seed VALUES, not just
     *    added/removed keys.
     *
     * @return array{objects: array<string,string>, settings: array<string,string>}
     */
    private function shape(PDO $pdo): array
    {
        $objects = [];
        $rows = $pdo->query(
            "SELECT type, name, sql FROM sqlite_master
             WHERE name NOT LIKE 'sqlite_%' AND sql IS NOT NULL
             ORDER BY type, name"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $objects[(string) $r['type'] . ' ' . (string) $r['name']] = $this->normalizeSql((string) $r['sql']);
        }

        $settings = [];
        if (array_key_exists('table settings', $objects)) {
            $rows = $pdo->query('SELECT `key`, `value` FROM settings ORDER BY `key`')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $settings[(string) $row['key']] = (string) $row['value'];
            }
        }

        return ['objects' => $objects, 'settings' => $settings];
    }

    /** Collapse whitespace so reformatting is ignored but real DDL changes are not. */
    private function normalizeSql(string $sql): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $sql));
    }

    /**
     * @param array{objects: array<string,string>, settings: array<string,string>} $fresh
     * @param array{objects: array<string,string>, settings: array<string,string>} $current
     * @return list<string> human-readable drift descriptions (empty when in sync)
     */
    private function diff(array $fresh, array $current): array
    {
        $out = [];
        foreach (['objects' => 'object', 'settings' => 'setting'] as $kind => $label) {
            foreach (array_diff_key($fresh[$kind], $current[$kind]) as $name => $_) {
                $out[] = "missing {$label} in template: {$name}";
            }
            foreach (array_diff_key($current[$kind], $fresh[$kind]) as $name => $_) {
                $out[] = "stale {$label} in template (not in schema): {$name}";
            }
            foreach ($fresh[$kind] as $name => $value) {
                if (array_key_exists($name, $current[$kind]) && $current[$kind][$name] !== $value) {
                    $out[] = $kind === 'objects' ? "DDL differs for {$name}" : "seed value differs for setting {$name}";
                }
            }
        }
        return $out;
    }
}
