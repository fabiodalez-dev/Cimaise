<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Support\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class CommandsController
{
    public function __construct(private Database $db, private Twig $view) {}

    public function index(Request $request, Response $response): Response
    {
        return $this->view->render($response, 'admin/commands.twig', [
            'page_title' => 'System Commands'
        ]);
    }

    public function execute(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $command = $data['command'] ?? '';
        $args = $data['args'] ?? [];
        $csrf = (string)($data['csrf'] ?? $request->getHeaderLine('X-CSRF-Token'));
        
        // SECURITY: Verify CSRF token
        if (!is_string($csrf) || !isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $csrf)) {
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
            'media:normalize-paths'
        ];

        if (!in_array($command, $allowedCommands)) {
            throw new \InvalidArgumentException("Command not allowed: $command");
        }

        $consolePath = dirname(__DIR__, 3) . '/bin/console';
        if (!is_executable($consolePath)) {
            throw new \RuntimeException("Console script not executable: $consolePath");
        }

        // Build command
        $cmd = "php $consolePath $command";
        
        // Add arguments safely
        foreach ($args as $key => $value) {
            if (is_string($key) && !empty($key)) {
                $cmd .= " --" . escapeshellarg($key);
                if (!empty($value) && $value !== true) {
                    $cmd .= "=" . escapeshellarg((string)$value);
                }
            } elseif (!empty($value)) {
                $cmd .= " " . escapeshellarg((string)$value);
            }
        }

        // Add timeout and error handling
        $cmd .= " 2>&1";
        
        $startTime = microtime(true);
        
        // Execute command
        ob_start();
        $exitCode = 0;
        $output = [];
        
        exec($cmd, $output, $exitCode);
        
        $duration = round(microtime(true) - $startTime, 2);
        
        return [
            'success' => $exitCode === 0,
            'output' => implode("\n", $output),
            'exit_code' => $exitCode,
            'duration' => $duration,
            'command' => $command
        ];
    }

    private function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
