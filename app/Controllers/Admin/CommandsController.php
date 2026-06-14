<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Support\Database;
use App\Services\BaseUrlService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class CommandsController extends BaseController
{
    public function __construct(Database $db, private readonly Twig $view)
    {
        parent::__construct();
        // $db kept on the signature for DI uniformity but currently unused.
        unset($db);
    }

    public function index(Request $request, Response $response): Response
    {
        $baseUrl = BaseUrlService::getCurrentBaseUrl();

        return $this->view->render($response, 'admin/commands.twig', [
            'page_title' => 'System Commands',
            'detected_base_url' => $baseUrl,
            'csrf' => $_SESSION['csrf'] ?? ''
        ]);
    }

    public function execute(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $command = $data['command'] ?? '';
        $args = $data['args'] ?? [];
        $csrf = (string)($data['csrf'] ?? $request->getHeaderLine('X-CSRF-Token'));

        // SECURITY: Verify CSRF token
        if (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $csrf)) {
            return $this->jsonResponse($response, ['error' => 'Invalid CSRF token', 'success' => false], 403);
        }

        if (!$command) {
            return $this->jsonResponse($response, ['error' => 'No command specified', 'success' => false], 400);
        }

        try {
            $result = $this->runCommand($command, $args);
            return $this->jsonResponse($response, $result);
        } catch (\Throwable $e) {
            return $this->jsonResponse($response, [
                'error' => $e->getMessage(),
                'output' => '',
                'success' => false
            ], 500);
        }
    }

    private function runCommand(string $command, array $args = []): array
    {
        $allowedCommands = [
            'init',
            'db:migrate',
            'db:seed',
            'db:test',
            'images:generate',
            'sitemap:build',
            'diagnostics:report',
            'user:create',
            'media:normalize-paths',
            'analytics:cleanup',
            'analytics:summarize'
        ];

        if (!in_array($command, $allowedCommands)) {
            throw new \InvalidArgumentException("Command not allowed: $command");
        }

        $consolePath = dirname(__DIR__, 3) . '/bin/console';
        if (!is_executable($consolePath)) {
            throw new \RuntimeException("Console script not executable: $consolePath");
        }

        // Resolve a usable PHP CLI binary. The web-server user's PATH frequently
        // lacks one — exec("php ...") then fails with "php: command not found"
        // (exit 127) — and under mod_php PHP_BINARY points at the web SAPI, not a
        // CLI. So probe explicit locations instead of trusting bare "php".
        $phpBinary = $this->resolvePhpBinary();

        // Build command (M11: escape paths to prevent injection)
        $cmd = escapeshellarg($phpBinary) . " " . escapeshellarg($consolePath) . " " . escapeshellarg($command);

        // Add arguments safely
        foreach ($args as $key => $value) {
            if (is_string($key) && !empty($key)) {
                // Validate key is a valid option name (alphanumeric and hyphens only)
                if (!preg_match('/^[a-zA-Z0-9-]+$/', $key)) {
                    throw new \InvalidArgumentException("Invalid argument key: $key");
                }
                if ($value === true) {
                    // Boolean flag
                    $cmd .= " --" . $key;
                } elseif (!empty($value)) {
                    // Key=value option
                    $cmd .= " --" . $key . "=" . escapeshellarg((string)$value);
                }
            } elseif (!empty($value)) {
                $cmd .= " " . escapeshellarg((string)$value);
            }
        }

        // Add timeout and error handling
        $cmd .= " 2>&1";

        $startTime = microtime(true);

        // Execute command
        $exitCode = 0;
        $output = [];

        // $cmd is built only from an allow-listed command name (see $allowedCommands),
        // a resolved PHP binary path and console path, all passed through
        // escapeshellarg(); option keys are validated against /^[a-zA-Z0-9-]+$/.
        exec($cmd, $output, $exitCode); // nosemgrep

        $duration = round(microtime(true) - $startTime, 2);

        return [
            'success' => $exitCode === 0,
            'output' => implode("\n", $output),
            'exit_code' => $exitCode,
            'duration' => $duration,
            'command' => $command
        ];
    }

    /**
     * Resolve a usable PHP CLI binary for exec().
     *
     * Order: explicit override (PHP_CLI_BINARY env) -> the running binary when it
     * is actually a php CLI (guarded, since mod_php exposes the web SAPI here) ->
     * common absolute install paths (the web-server user's PATH is often empty)
     * -> bare "php" as a last resort.
     */
    private function resolvePhpBinary(): string
    {
        $override = getenv('PHP_CLI_BINARY');
        if (!is_string($override) || $override === '') {
            $override = $_ENV['PHP_CLI_BINARY'] ?? '';
        }
        if (is_string($override) && $override !== '' && @is_executable($override)) {
            return $override;
        }

        // Only trust the running binary when it is an actual CLI (php, php8.4, ...).
        // Under FPM/mod_php PHP_BINARY is php-fpm/php-cgi, which cannot run scripts.
        if (defined('PHP_BINARY') && @is_executable(PHP_BINARY)
            && preg_match('/^php(\d+(\.\d+)*)?$/', basename(PHP_BINARY)) === 1) {
            return PHP_BINARY;
        }

        $candidates = [
            '/opt/homebrew/bin/php',
            '/usr/local/bin/php',
            '/usr/bin/php',
            '/opt/homebrew/opt/php/bin/php',
            '/usr/local/opt/php/bin/php',
            '/opt/cpanel/ea-php83/root/usr/bin/php',
            '/opt/cpanel/ea-php84/root/usr/bin/php',
        ];
        foreach ($candidates as $candidate) {
            if (@is_executable($candidate)) {
                return $candidate;
            }
        }

        return 'php';
    }
}
