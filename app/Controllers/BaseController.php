<?php
declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ServerRequestInterface as Request;

abstract class BaseController
{
    protected string $basePath;

    public function __construct()
    {
        $this->basePath = $this->getBasePath();
    }

    protected function getBasePath(): string
    {
        $basePath = dirname($_SERVER['SCRIPT_NAME']);
        $basePath = $basePath === '/' ? '' : $basePath;

        // Remove /public from the path if present (since document root should be public/)
        if (str_ends_with($basePath, '/public')) {
            $basePath = substr($basePath, 0, -7); // Remove '/public'
        }

        return $basePath;
    }

    protected function redirect(string $path): string
    {
        return $this->basePath . $path;
    }

    /**
     * Validate CSRF token from request body or header.
     * Uses timing-safe comparison to prevent timing attacks.
     */
    protected function validateCsrf(Request $request): bool
    {
        $data = (array)$request->getParsedBody();
        $token = $data['csrf'] ?? $request->getHeaderLine('X-CSRF-Token');
        return \is_string($token) && isset($_SESSION['csrf']) && \hash_equals($_SESSION['csrf'], $token);
    }
}