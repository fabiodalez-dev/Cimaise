<?php

declare(strict_types=1);

use App\Middlewares\TrustedProxyMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * The reverse-proxy correction underpins every canonical / og:url / JSON-LD @id,
 * so it must (a) trust X-Forwarded-* ONLY from a listed proxy and (b) never let
 * an arbitrary client forge the public origin.
 */
final class TrustedProxyMiddlewareTest extends TestCase
{
    private ?string $prevTrusted = null;
    private ?string $prevEnv = null;
    /** @var array<string, mixed> */
    private array $serverSnapshot = [];

    protected function setUp(): void
    {
        $this->prevTrusted = getenv('TRUSTED_PROXIES') ?: null;
        $this->prevEnv = getenv('APP_ENV') ?: null;
        putenv('TRUSTED_PROXIES');
        putenv('APP_ENV');
        // process() mutates these $_SERVER keys in the trusted-proxy branch and
        // phpunit.xml does not isolate processes, so snapshot + restore to keep a
        // rewritten origin from leaking into later tests.
        foreach (['HTTPS', 'REQUEST_SCHEME', 'HTTP_HOST', 'SERVER_NAME', 'SERVER_PORT'] as $k) {
            $this->serverSnapshot[$k] = $_SERVER[$k] ?? null;
        }
    }

    protected function tearDown(): void
    {
        $this->prevTrusted === null ? putenv('TRUSTED_PROXIES') : putenv('TRUSTED_PROXIES=' . $this->prevTrusted);
        $this->prevEnv === null ? putenv('APP_ENV') : putenv('APP_ENV=' . $this->prevEnv);
        foreach ($this->serverSnapshot as $k => $v) {
            if ($v === null) {
                unset($_SERVER[$k]);
            } else {
                $_SERVER[$k] = $v;
            }
        }
    }

    /** Runs the middleware and returns the URI the downstream handler received. */
    private function dispatch(ServerRequestInterface $request): UriInterface
    {
        $handler = new class implements RequestHandlerInterface {
            public ?UriInterface $seenUri = null;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->seenUri = $request->getUri();
                return new Response();
            }
        };
        (new TrustedProxyMiddleware())->process($request, $handler);
        self::assertNotNull($handler->seenUri, 'handler was not invoked');
        return $handler->seenUri;
    }

    /** @param array<string, string> $headers */
    private function request(string $remoteAddr, array $headers = []): ServerRequestInterface
    {
        $req = (new ServerRequestFactory())->createServerRequest(
            'GET',
            'http://internal.local/album/x',
            ['REMOTE_ADDR' => $remoteAddr]
        );
        foreach ($headers as $name => $value) {
            $req = $req->withHeader($name, $value);
        }
        return $req;
    }

    public function testUntrustedPeerLeavesUriUntouched(): void
    {
        // No TRUSTED_PROXIES configured (default): forwarded headers are ignored.
        $uri = $this->dispatch($this->request('203.0.113.9', [
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'evil.example.com',
        ]));
        self::assertSame('http', $uri->getScheme());
        self::assertSame('internal.local', $uri->getHost());
    }

    public function testTrustedProxyRewritesSchemeHostAndPort(): void
    {
        putenv('TRUSTED_PROXIES=10.0.0.1');
        $uri = $this->dispatch($this->request('10.0.0.1', [
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'www.example.com',
            'X-Forwarded-Port' => '8443',
        ]));
        self::assertSame('https', $uri->getScheme());
        self::assertSame('www.example.com', $uri->getHost());
        self::assertSame(8443, $uri->getPort());
        self::assertSame('www.example.com:8443', $uri->getAuthority());
    }

    public function testDefaultPortIsDroppedFromAuthority(): void
    {
        putenv('TRUSTED_PROXIES=10.0.0.1');
        $uri = $this->dispatch($this->request('10.0.0.1', [
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'www.example.com',
            'X-Forwarded-Port' => '443',
        ]));
        self::assertNull($uri->getPort());
        self::assertSame('www.example.com', $uri->getAuthority());
    }

    public function testForgedHeaderFromUntrustedPeerIsIgnoredEvenWithTrustedListSet(): void
    {
        putenv('TRUSTED_PROXIES=10.0.0.1');
        // A different, non-listed client cannot forge the origin.
        $uri = $this->dispatch($this->request('203.0.113.9', [
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'evil.example.com',
        ]));
        self::assertSame('http', $uri->getScheme());
        self::assertSame('internal.local', $uri->getHost());
    }

    public function testWildcardOnlyHonouredInDevelopment(): void
    {
        putenv('TRUSTED_PROXIES=*');
        putenv('APP_ENV=production');
        $prod = $this->dispatch($this->request('203.0.113.9', ['X-Forwarded-Proto' => 'https']));
        self::assertSame('http', $prod->getScheme(), 'wildcard must NOT trust in production');

        putenv('APP_ENV=development');
        $dev = $this->dispatch($this->request('203.0.113.9', ['X-Forwarded-Proto' => 'https']));
        self::assertSame('https', $dev->getScheme(), 'wildcard trusts any peer in development');
    }
}
