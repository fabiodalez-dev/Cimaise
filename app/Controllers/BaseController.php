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

    /**
     * Return JSON error response for invalid CSRF token.
     * For use in AJAX/API endpoints.
     */
    protected function csrfErrorJson(\Psr\Http\Message\ResponseInterface $response): \Psr\Http\Message\ResponseInterface
    {
        $response->getBody()->write(json_encode(['ok' => false, 'error' => 'Invalid CSRF token']));
        return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
    }

    /**
     * Check if current user is an authenticated admin.
     */
    protected function isAdmin(): bool
    {
        return !empty($_SESSION['admin_id']);
    }

    /**
     * Check if the current request is an AJAX/JSON request.
     */
    protected function isAjaxRequest(Request $request): bool
    {
        try {
            $hdr = $request->getHeaderLine('X-Requested-With');
            $acc = $request->getHeaderLine('Accept');
            return (stripos($hdr, 'XMLHttpRequest') !== false) || (stripos($acc, 'application/json') !== false);
        } catch (\Throwable) {
            return false;
        }
    }
}