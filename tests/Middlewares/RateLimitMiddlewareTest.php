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
