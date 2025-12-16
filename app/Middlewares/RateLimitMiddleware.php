<?php
declare(strict_types=1);

namespace App\Middlewares;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;

/**
 * Rate limiting middleware with file-based storage.
 * Uses server-side file storage indexed by IP to prevent session cookie bypass.
 */
class RateLimitMiddleware implements MiddlewareInterface
{
    private string $storageDir;

    public function __construct(private int $maxAttempts = 5, private int $windowSec = 600)
    {
        // Use storage directory for rate limit data
        $this->storageDir = dirname(__DIR__, 2) . '/storage/rate_limits';
        if (!is_dir($this->storageDir)) {
            @mkdir($this->storageDir, 0755, true);
        }
    }

    /**
     * Get client IP address, accounting for reverse proxies.
     * Only trusts X-Forwarded-For if TRUSTED_PROXIES env is configured.
     */
    private function getClientIp(Request $request): string
    {
        $serverParams = $request->getServerParams();
        $remoteAddr = $serverParams['REMOTE_ADDR'] ?? 'unknown';

        // Only trust X-Forwarded-For if request comes from a trusted proxy
        $trustedProxies = getenv('TRUSTED_PROXIES') ?: '';
        if ($trustedProxies !== '' && $remoteAddr !== 'unknown') {
            $trustedList = array_map('trim', explode(',', $trustedProxies));

            // Validate each proxy IP (except wildcard)
            $trustedList = array_filter($trustedList, function($ip) {
                return $ip === '*' || filter_var($ip, FILTER_VALIDATE_IP) !== false;
            });

            if (empty($trustedList)) {
                error_log('WARNING: No valid IPs in TRUSTED_PROXIES configuration');
                return $remoteAddr;
            }

            // Wildcard only allowed in development mode
            $isWildcard = \in_array('*', $trustedList, true);
            $allowWildcard = (getenv('APP_ENV') === 'development');

            if ($isWildcard && !$allowWildcard) {
                error_log('SECURITY WARNING: Wildcard in TRUSTED_PROXIES not allowed in production');
                return $remoteAddr;
            }

            // Check if remote address is in trusted proxies list
            if (\in_array($remoteAddr, $trustedList, true) || ($isWildcard && $allowWildcard)) {
                // Check X-Forwarded-For header
                $forwardedFor = $request->getHeaderLine('X-Forwarded-For');
                if ($forwardedFor !== '') {
                    // X-Forwarded-For can contain multiple IPs: client, proxy1, proxy2
                    // The leftmost IP is the original client
                    $ips = array_map('trim', explode(',', $forwardedFor));
                    $clientIp = $ips[0] ?? '';

                    // Validate IP format to prevent spoofing
                    if (filter_var($clientIp, FILTER_VALIDATE_IP)) {
                        return $clientIp;
                    }
                }

                // Fallback: check X-Real-IP (used by nginx)
                $realIp = $request->getHeaderLine('X-Real-IP');
                if ($realIp !== '' && filter_var($realIp, FILTER_VALIDATE_IP)) {
                    return $realIp;
                }
            }
        }

        return $remoteAddr;
    }

    /**
     * Get endpoint identifier with precise matching.
     */
    private function getEndpointIdentifier(string $path): string
    {
        // More precise endpoint matching using regex
        if (preg_match('#/album/[^/]+/unlock$#', $path)) {
            return 'album_unlock:' . $path;
        }
        if ($path === '/login' || $path === '/admin/login') {
            return 'login';
        }
        return 'generic';
    }

    /**
     * Get rate limit data from file storage.
     * @return int[] Array of timestamps
     */
    private function getAttempts(string $key): array
    {
        $file = $this->storageDir . '/' . $key . '.json';
        if (!file_exists($file)) {
            return [];
        }

        $data = @file_get_contents($file);
        if ($data === false) {
            return [];
        }

        $attempts = json_decode($data, true);
        return is_array($attempts) ? $attempts : [];
    }

    /**
     * Save rate limit data to file storage.
     * @param int[] $attempts Array of timestamps
     */
    private function saveAttempts(string $key, array $attempts): void
    {
        $file = $this->storageDir . '/' . $key . '.json';

        if (empty($attempts)) {
            @unlink($file);
            return;
        }

        @file_put_contents($file, json_encode($attempts), LOCK_EX);
    }

    /**
     * Clean up old rate limit files (call periodically).
     */
    public static function cleanup(string $storageDir, int $maxAge = 86400): void
    {
        $files = glob($storageDir . '/*.json');
        $now = time();

        foreach ($files as $file) {
            if ($now - filemtime($file) > $maxAge) {
                @unlink($file);
            }
        }
    }

    public function process(Request $request, Handler $handler): Response
    {
        $ip = $this->getClientIp($request);
        $path = $request->getUri()->getPath();

        // Use different keys for different endpoints to track separately
        $keyIdentifier = $this->getEndpointIdentifier($path);
        $key = 'rl_' . sha1($keyIdentifier . ':' . $ip);
        $now = time();

        // Get attempts from file storage
        $attempts = $this->getAttempts($key);

        // Purge old attempts outside the window
        $attempts = array_filter($attempts, fn($ts) => ($now - (int)$ts) < $this->windowSec);

        if (count($attempts) >= $this->maxAttempts) {
            $remaining = $this->windowSec - ($now - min($attempts));
            $resp = new \Slim\Psr7\Response(429);
            $resp->getBody()->write('Too Many Attempts. Please try again in ' . ceil($remaining / 60) . ' minutes.');
            return $resp->withHeader('Retry-After', (string)$remaining);
        }

        $response = $handler->handle($request);

        // Detect failed attempts based on response type
        $isFailedAttempt = false;
        $isSuccessfulAuth = false;
        $statusCode = $response->getStatusCode();

        // For login pages: check response body for error message (supports multiple languages)
        $body = (string)$response->getBody();
        // Rewind stream so downstream can read body (if seekable)
        if ($response->getBody()->isSeekable()) {
            $response->getBody()->rewind();
        } else {
            // Recreate body for non-seekable streams
            $stream = fopen('php://temp', 'r+');
            fwrite($stream, $body);
            rewind($stream);
            $response = $response->withBody(new \Slim\Psr7\Stream($stream));
        }
        if ($statusCode === 200 && (
            str_contains($body, 'Credenziali non valide') ||
            str_contains($body, 'Invalid credentials') ||
            str_contains($body, 'Login failed') ||
            str_contains($body, 'Account disattivato') ||
            str_contains($body, 'Account disabled')
        )) {
            $isFailedAttempt = true;
        }

        // For album unlock: check for redirect with error parameter
        if ($statusCode === 302) {
            $location = $response->getHeaderLine('Location');
            if (str_contains($location, 'error=1') || str_contains($location, 'error=nsfw')) {
                $isFailedAttempt = true;
            }
            // For login: redirect back to /login indicates failure
            if ($keyIdentifier === 'login' && str_contains($location, '/login')) {
                $isFailedAttempt = true;
            }
            // Successful auth: redirect away from login without error
            if ($keyIdentifier === 'login' && !str_contains($location, '/login') && !str_contains($location, 'error=')) {
                $isSuccessfulAuth = true;
            }
            // Successful unlock: redirect without error parameter
            if (str_starts_with($keyIdentifier, 'album_unlock:') && !str_contains($location, 'error=')) {
                $isSuccessfulAuth = true;
            }
        }

        if ($isFailedAttempt) {
            $attempts[] = $now;
            $this->saveAttempts($key, $attempts);
        } elseif ($isSuccessfulAuth) {
            // Only reset rate limit for this specific endpoint on successful auth
            $this->saveAttempts($key, []);
        } else {
            // Save pruned attempts (no new failure, no reset)
            $this->saveAttempts($key, $attempts);
        }

        return $response;
    }
}
