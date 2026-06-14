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
    /**
     * Response header set by AuthController to signal the auth outcome.
     * Used for login throttling detection instead of matching localized body
     * text (which silently breaks whenever translations change).
     */
    private const AUTH_RESULT_HEADER = 'X-Auth-Result';

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

    /**
     * Enforce the per-IP+endpoint limit: short-circuit with 429 when exceeded,
     * otherwise run the handler, record failures / clear on success, and strip
     * the internal auth-result sentinel from the outgoing response.
     */
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

        // Never leak the internal auth-outcome signal to the client.
        return $response->withoutHeader(self::AUTH_RESULT_HEADER);
    }

    /** Resolve the client IP, trusting forwarded headers only behind TRUSTED_PROXIES. */
    private function getClientIp(Request $request): string
    {
        $serverParams = $request->getServerParams();
        $remoteAddr = $serverParams['REMOTE_ADDR'] ?? 'unknown';

        // H1: Only trust forwarded headers when TRUSTED_PROXIES is configured
        $trustedProxies = getenv('TRUSTED_PROXIES') ?: '';
        if ($trustedProxies !== '' && $remoteAddr !== 'unknown') {
            $trustedList = array_map('trim', explode(',', $trustedProxies));
            $trustedList = array_filter($trustedList, fn ($ip) => $ip === '*' || filter_var($ip, FILTER_VALIDATE_IP) !== false);

            $isWildcard = in_array('*', $trustedList, true);
            $allowWildcard = getenv('APP_ENV') === 'development';

            if ($isWildcard && !$allowWildcard) {
                return $remoteAddr;
            }

            if (in_array($remoteAddr, $trustedList, true) || ($isWildcard && $allowWildcard)) {
                // Check forwarded headers in priority order
                $forwardedHeaders = [
                    'HTTP_CF_CONNECTING_IP',
                    'HTTP_X_FORWARDED_FOR',
                    'HTTP_X_REAL_IP',
                ];

                foreach ($forwardedHeaders as $header) {
                    if (!empty($serverParams[$header])) {
                        $ip = trim(explode(',', $serverParams[$header])[0]);
                        if (filter_var($ip, FILTER_VALIDATE_IP)) {
                            return $ip;
                        }
                    }
                }
            }
        }

        return $remoteAddr;
    }

    /** Load the stored attempt timestamps, dropping any outside the current window. */
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
                fn ($timestamp) => ($currentTime - $timestamp) < $this->windowSec
            );

            return array_values($validAttempts);
        } catch (\Throwable) {
            return [];
        }
    }

    /** Persist the attempt timestamps to the counter file (best-effort, file-locked). */
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

    /** Delete the counter file, resetting the attempt count for this key. */
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

    /**
     * Check if the request was a failed attempt (e.g., failed login).
     *
     * Login outcome is read from the AUTH_RESULT_HEADER sentinel set by
     * AuthController, never from localized body text. For non-login endpoints
     * we fall back to treating 4xx as a failure.
     */
    private function isFailedAttempt(Request $request, Response $response): bool
    {
        if (str_contains($request->getUri()->getPath(), '/login')) {
            return $response->getHeaderLine(self::AUTH_RESULT_HEADER) === 'failed';
        }

        // For other endpoints, consider 4xx errors as failed attempts
        return $response->getStatusCode() >= 400 && $response->getStatusCode() < 500;
    }

    /**
     * Whether the response counts as a success that clears the counter. For
     * `/login` only an explicit `X-Auth-Result: success` qualifies; other
     * endpoints treat any 2xx as success.
     */
    private function isSuccessfulAttempt(Request $request, Response $response): bool
    {
        // For login endpoints, only an explicit success signal clears the counter.
        // (Previously any 2xx — including the rendered error page — wrongly cleared it.)
        if (str_contains($request->getUri()->getPath(), '/login')) {
            return $response->getHeaderLine(self::AUTH_RESULT_HEADER) === 'success';
        }

        return $response->getStatusCode() >= 200 && $response->getStatusCode() < 300;
    }

    /** Build the 429 Too Many Requests JSON response with a Retry-After header. */
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
