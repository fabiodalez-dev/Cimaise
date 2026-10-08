<?php

declare(strict_types=1);

use App\Middlewares\RateLimitMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * RateLimitMiddleware's default mode is outcome-based: it only records
 * recognised failures (bad login, wrong album password, 4xx download). For a
 * "generic" endpoint such as the contact form (which redirects with ?sent=1
 * on success) or the search page (200) nothing is ever recorded, so the
 * configured limit was a no-op. The plain-throttle mode counts every request.
 */
final class RateLimitMiddlewareTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cimaise_rl2_' . uniqid('', true);
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function testDefaultModeDoesNotThrottleSuccessfulGenericRequests(): void
    {
        $mw = new RateLimitMiddleware(2, 600, false, $this->dir);
        $handler = $this->redirectHandler('/about?sent=1');

        for ($i = 0; $i < 6; $i++) {
            self::assertSame(302, $mw->process($this->request('/about/contact'), $handler)->getStatusCode());
        }
    }

    public function testCountAllModeThrottlesSuccessfulGenericRequests(): void
    {
        $mw = new RateLimitMiddleware(2, 600, true, $this->dir);
        $handler = $this->redirectHandler('/about?sent=1');

        self::assertSame(302, $mw->process($this->request('/about/contact'), $handler)->getStatusCode());
        self::assertSame(302, $mw->process($this->request('/about/contact'), $handler)->getStatusCode());

        $blocked = $mw->process($this->request('/about/contact'), $handler);
        self::assertSame(429, $blocked->getStatusCode());
        self::assertTrue($blocked->hasHeader('Retry-After'));
    }

    public function testCountAllModeKeepsClientsSeparate(): void
    {
        $mw = new RateLimitMiddleware(1, 600, true, $this->dir);
        $handler = $this->redirectHandler('/about?sent=1');

        self::assertSame(302, $mw->process($this->request('/about/contact', '198.51.100.1'), $handler)->getStatusCode());
        self::assertSame(429, $mw->process($this->request('/about/contact', '198.51.100.1'), $handler)->getStatusCode());
        self::assertSame(302, $mw->process($this->request('/about/contact', '198.51.100.2'), $handler)->getStatusCode());
    }

    public function testCountAllModeKeepsGenericEndpointsSeparate(): void
    {
        $mw = new RateLimitMiddleware(1, 600, true, $this->dir);
        $handler = $this->redirectHandler('/about?sent=1');

        self::assertSame(302, $mw->process($this->request('/search'), $handler)->getStatusCode());
        self::assertSame(429, $mw->process($this->request('/search'), $handler)->getStatusCode());
        // Exhausting /search must not block the contact form for the same IP.
        self::assertSame(302, $mw->process($this->request('/about/contact'), $handler)->getStatusCode());
    }

    public function testNsfwConsentCounterIgnoresTheAlbumSlug(): void
    {
        $mw = new RateLimitMiddleware(1, 600, true, $this->dir);
        $handler = $this->redirectHandler('/album/one');

        self::assertSame(302, $mw->process($this->request('/album/one/nsfw-confirm'), $handler)->getStatusCode());
        // Rotating the slug must not hand out a fresh counter.
        self::assertSame(429, $mw->process($this->request('/album/two/nsfw-confirm'), $handler)->getStatusCode());
    }

    public function testRoutePatternWinsOverTheRawPath(): void
    {
        // Subdirectory install: the URI carries a base path and a slug, but the
        // matched route pattern must drive the bucket.
        $mw = new RateLimitMiddleware(1, 600, true, $this->dir);
        $handler = $this->redirectHandler('/album/one');

        $first = $this->routedRequest('/site/album/one/nsfw-confirm', '/album/{slug}/nsfw-confirm');
        $second = $this->routedRequest('/site/album/two/nsfw-confirm', '/album/{slug}/nsfw-confirm');
        self::assertSame(302, $mw->process($first, $handler)->getStatusCode());
        self::assertSame(429, $mw->process($second, $handler)->getStatusCode());
    }

    public function testCountAllModeRecordsTheRequestBeforeForwarding(): void
    {
        $mw = new RateLimitMiddleware(1, 600, true, $this->dir);
        $seenFiles = 0;
        $dir = $this->dir;
        $handler = new class ($seenFiles, $dir) implements RequestHandlerInterface {
            public function __construct(private int &$seenFiles, private readonly string $dir)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                // The slot must already be persisted while the handler runs.
                $this->seenFiles = count(glob($this->dir . '/rl_*.json') ?: []);

                return new Response(200);
            }
        };

        $mw->process($this->request('/search'), $handler);
        self::assertSame(1, $seenFiles);
    }

    public function testCountAllModeDoesNotCallTheHandlerOnceBlocked(): void
    {
        $mw = new RateLimitMiddleware(1, 600, true, $this->dir);
        $calls = 0;
        $handler = new class ($calls) implements RequestHandlerInterface {
            public function __construct(private int &$calls)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->calls++;

                return new Response(200);
            }
        };

        $mw->process($this->request('/search'), $handler);
        $mw->process($this->request('/search'), $handler);
        self::assertSame(1, $calls, 'the throttled request must never reach the handler');
    }

    private function request(string $path, string $ip = '203.0.113.9'): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest(
            'POST',
            'http://example.test' . $path,
            ['REMOTE_ADDR' => $ip]
        );
    }

    private function routedRequest(string $path, string $pattern, string $ip = '203.0.113.9'): ServerRequestInterface
    {
        $route = new \Slim\Routing\Route(['POST'], $pattern, static fn () => null, new \Slim\Psr7\Factory\ResponseFactory(), new \Slim\CallableResolver());

        return $this->request($path, $ip)->withAttribute(\Slim\Routing\RouteContext::ROUTE, $route);
    }

    private function redirectHandler(string $location): RequestHandlerInterface
    {
        return new class ($location) implements RequestHandlerInterface {
            public function __construct(private readonly string $location)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Response(302))->withHeader('Location', $this->location);
            }
        };
    }
}
