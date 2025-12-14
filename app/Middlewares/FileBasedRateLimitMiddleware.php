<?php
declare(strict_types=1);

namespace App\Middlewares;

use App\Support\Logger;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/**
 * File-based rate limiting middleware without Redis dependency
 * Uses filesystem for persistent rate limiting across server restarts
 */
class FileBasedRateLimitMiddleware implements MiddlewareInterface
{
    private string $storageDir;
    private int $maxAttempts;
    private int $windowSec;
    private string $keyPrefix;

    public function __construct(
        string $storageDir, 
        int $maxAttempts = 5, 
        int $windowSec = 600,
        string $keyPrefix = 'rate_limit'
    ) {
        $this->storageDir = rtrim($storageDir, '/');
        $this->maxAttempts = $maxAttempts;
        $this->windowSec = $windowSec;
        $this->keyPrefix = $keyPrefix;
        
        // Ensure storage directory exists
        if (!is_dir($this->storageDir)) {
            @mkdir($this->storageDir, 0750, true);
        }
    }

    public function process(Request $request, Handler $handler): Response
    {
        $ip = $this->getClientIp($request);
        $path = $request->getUri()->getPath();
        $method = $request->getMethod();
        
        // Create unique key for this IP + endpoint
        $key = $this->keyPrefix . '_' . hash('sha256', $ip . ':' . $path . ':' . $method);
        $filePath = $this->storageDir . '/' . $key . '.json';
        
        $now = time();
        $attempts = $this->loadAttempts($filePath, $now);
        
        // Check if rate limit exceeded
        if (count($attempts) >= $this->maxAttempts) {
            return $this->createRateLimitResponse();
        }

        // Process the request
        $response = $handler->handle($request);
        
        // First clear on success, then record failures
        if ($this->isSuccessfulAttempt($request, $response)) {
            $this->clearAttempts($filePath);
        } elseif ($this->isFailedAttempt($request, $response)) {
            $attempts[] = $now;
            $this->saveAttempts($filePath, $attempts);
        }

        return $response;
    }

    private function getClientIp(Request $request): string
    {
        $serverParams = $request->getServerParams();
        
        // Check for forwarded IP (behind proxy/load balancer)
        $forwardedIps = [
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_X_FORWARDED_FOR',      // Standard proxy header
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'                // Direct connection
        ];
        
        foreach ($forwardedIps as $header) {
            if (!empty($serverParams[$header])) {
                $ip = trim(explode(',', $serverParams[$header])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        return $serverParams['REMOTE_ADDR'] ?? 'unknown';
    }

    private function loadAttempts(string $filePath, int $currentTime): array
    {
        if (!file_exists($filePath)) {
            return [];
        }

        try {
            $content = file_get_contents($filePath);
            if ($content === false) {
                return [];
            }

            $data = json_decode($content, true);
            if (!is_array($data) || !isset($data['attempts'])) {
                return [];
            }

            // Filter out expired attempts (outside window)
            $validAttempts = array_filter(
                $data['attempts'], 
                fn($timestamp) => ($currentTime - $timestamp) < $this->windowSec
            );

            return array_values($validAttempts);
        } catch (\Throwable) {
            return [];
        }
    }

    private function saveAttempts(string $filePath, array $attempts): void
    {
        try {
            $data = [
                'attempts' => $attempts,
                'last_updated' => time()
            ];
            
            file_put_contents($filePath, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
        } catch (\Throwable) {
            // Fail silently if we can't write (don't break the application)
            Logger::warning('FileBasedRateLimitMiddleware: Failed to save attempts', ['file' => $filePath], 'security');
        }
    }

    private function clearAttempts(string $filePath): void
    {
        try {
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        } catch (\Throwable) {
            Logger::warning('FileBasedRateLimitMiddleware: Failed to clear attempts file', ['file' => $filePath], 'security');
        }
    }

    private function isFailedAttempt(Request $request, Response $response): bool
    {
        $path = $request->getUri()->getPath();
        
        // For login endpoints handle precisely
        if (str_contains($path, '/login')) {
            $status = $response->getStatusCode();
            if ($status === 302) {
                $location = $response->getHeaderLine('Location');
                // Redirecting back to login implies failure; redirecting to /admin implies success
                if (str_contains($location, '/login')) {
                    return true;
                }
                return false;
            }

            // Check response body for error indicators
            $body = (string)$response->getBody();
            return str_contains($body, 'Credenziali non valide') ||
                   str_contains($body, 'Invalid credentials') ||
                   str_contains($body, 'Login failed') ||
                   str_contains($body, 'Account disattivato');
        }
        
        // For other endpoints, consider 4xx errors as failed attempts
        return $response->getStatusCode() >= 400 && $response->getStatusCode() < 500;
    }

    private function isSuccessfulAttempt(Request $request, Response $response): bool
    {
        $path = $request->getUri()->getPath();
        
        // For login endpoints
        if (str_contains($path, '/login')) {
            // Successful login typically redirects to admin dashboard
            if ($response->getStatusCode() === 302) {
                $location = $response->getHeaderLine('Location');
                return str_contains($location, '/admin') && !str_contains($location, '/login');
            }
        }
        
        return $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;
    }

    private function createRateLimitResponse(): Response
    {
        $response = new \Slim\Psr7\Response(429);
        
        $retryAfter = $this->windowSec;
        $errorMessage = json_encode([
            'error' => 'Too many attempts',
            'message' => "Rate limit exceeded. Try again in {$retryAfter} seconds.",
            'retry_after' => $retryAfter
        ]);
        
        $response->getBody()->write($errorMessage);
        
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Retry-After', (string)$retryAfter);
    }

    /**
     * Cleanup old rate limit files (call this periodically)
     */
    public function cleanup(): int
    {
        $cleaned = 0;
        $cutoffTime = time() - ($this->windowSec * 2); // Clean files older than 2x window
        
        try {
            $files = glob($this->storageDir . '/' . $this->keyPrefix . '_*.json');
            if ($files === false) {
                return 0;
            }
            
            foreach ($files as $file) {
                $mtime = filemtime($file);
                if ($mtime !== false && $mtime < $cutoffTime) {
                    if (unlink($file)) {
                        $cleaned++;
                    }
                }
            }
        } catch (\Throwable) {
            // Ignore cleanup errors
        }
        
        return $cleaned;
    }
}
