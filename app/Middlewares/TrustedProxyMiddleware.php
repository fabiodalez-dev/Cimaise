<?php

declare(strict_types=1);

namespace App\Middlewares;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Reverse-proxy awareness for canonical URLs.
 *
 * When the app runs behind a load balancer / TLS-terminating proxy, the direct
 * connection is plain HTTP to an internal host, so the PSR-7 request URI (and
 * $_SERVER) describe the *internal* origin, not the public one. Every canonical
 * link, og:url and JSON-LD @id built from that request would then point at the
 * wrong scheme/host — poison for SEO.
 *
 * This middleware rewrites the request scheme/host/port from the standard
 * X-Forwarded-* headers, but ONLY when the immediate peer (REMOTE_ADDR) is a
 * trusted proxy — the exact same TRUSTED_PROXIES convention the rate limiters
 * already use, so forged headers from arbitrary clients are ignored. It also
 * mirrors the correction into the $_SERVER superglobal so the $_SERVER-based
 * {@see \App\Services\BaseUrlService} (sitemap/feed) agrees with the PSR-7 URI.
 *
 * Registered outermost in public/index.php so the corrected URI is visible to
 * routing, Twig globals and every controller.
 */
final class TrustedProxyMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->remoteIsTrustedProxy($request)) {
            return $handler->handle($request);
        }

        $uri = $request->getUri();

        $proto = $this->firstForwardedValue($request, 'X-Forwarded-Proto');
        $host = $this->firstForwardedValue($request, 'X-Forwarded-Host');
        $portHeader = $this->firstForwardedValue($request, 'X-Forwarded-Port');

        $scheme = $uri->getScheme();
        if ($proto !== '') {
            $proto = strtolower($proto);
            if ($proto === 'https' || $proto === 'http') {
                $scheme = $proto;
                $uri = $uri->withScheme($scheme);
                // Mirror into $_SERVER for BaseUrlService / legacy $_SERVER readers.
                $_SERVER['HTTPS'] = $scheme === 'https' ? 'on' : 'off';
                $_SERVER['REQUEST_SCHEME'] = $scheme;
            }
        }

        if ($host !== '') {
            // X-Forwarded-Host may carry an explicit port (host:port).
            $hostOnly = $host;
            $embeddedPort = null;
            if (preg_match('/^\[(.+)\]:(\d+)$/', $host, $m)) {
                // IPv6 literal with port: [::1]:8443
                $hostOnly = '[' . $m[1] . ']';
                $embeddedPort = (int) $m[2];
            } elseif (substr_count($host, ':') === 1 && str_contains($host, ':')) {
                [$hostOnly, $maybePort] = explode(':', $host, 2);
                if (ctype_digit($maybePort)) {
                    $embeddedPort = (int) $maybePort;
                }
            }

            if ($this->isValidHost($hostOnly)) {
                $uri = $uri->withHost($hostOnly);
                $_SERVER['HTTP_HOST'] = $host;
                $_SERVER['SERVER_NAME'] = $hostOnly;

                if ($embeddedPort !== null && $portHeader === '') {
                    $portHeader = (string) $embeddedPort;
                }
            }
        }

        if ($portHeader !== '' && ctype_digit($portHeader)) {
            $port = (int) $portHeader;
            if ($port >= 1 && $port <= 65535) {
                // Drop the port when it is the default for the scheme, so the
                // authority (and canonical URLs) stay clean.
                $isDefault = ($scheme === 'https' && $port === 443)
                    || ($scheme === 'http' && $port === 80);
                $uri = $uri->withPort($isDefault ? null : $port);
                $_SERVER['SERVER_PORT'] = (string) $port;
            }
        }

        return $handler->handle($request->withUri($uri));
    }

    /**
     * Whether the immediate peer is listed in TRUSTED_PROXIES. Wildcard ('*')
     * is only honoured in development, mirroring the rate limiters.
     */
    private function remoteIsTrustedProxy(ServerRequestInterface $request): bool
    {
        $serverParams = $request->getServerParams();
        $remoteAddr = $serverParams['REMOTE_ADDR'] ?? '';
        if ($remoteAddr === '') {
            return false;
        }

        $trustedProxies = getenv('TRUSTED_PROXIES') ?: '';
        if ($trustedProxies === '') {
            return false;
        }

        $trustedList = array_map(trim(...), explode(',', $trustedProxies));
        $trustedList = array_filter(
            $trustedList,
            fn ($ip) => $ip === '*' || filter_var($ip, FILTER_VALIDATE_IP) !== false
        );

        $isWildcard = in_array('*', $trustedList, true);
        $allowWildcard = getenv('APP_ENV') === 'development';

        if ($isWildcard) {
            return $allowWildcard;
        }

        return in_array($remoteAddr, $trustedList, true);
    }

    /** First comma-separated value of a forwarded header, trimmed. */
    private function firstForwardedValue(ServerRequestInterface $request, string $header): string
    {
        $line = $request->getHeaderLine($header);
        if ($line === '') {
            return '';
        }
        return trim(explode(',', $line)[0]);
    }

    /** Reject header hosts that are not a bare hostname / IP (defense-in-depth). */
    private function isValidHost(string $host): bool
    {
        if ($host === '' || strlen($host) > 255) {
            return false;
        }
        // IPv6 literal in brackets.
        if (preg_match('/^\[[0-9A-Fa-f:]+\]$/', $host)) {
            return true;
        }
        // Hostname or IPv4: letters, digits, dots and hyphens only.
        return preg_match('/^[A-Za-z0-9.\-]+$/', $host) === 1;
    }
}
