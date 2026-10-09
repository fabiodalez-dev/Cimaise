<?php

declare(strict_types=1);

use App\Middlewares\FileBasedRateLimitMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * The file-based limiter has two modes. The default (login) mode only records
 * failures and resets on success. The plain-throttle mode must count EVERY
 * request: the public analytics beacon always answers 204, so in the default
 * mode its 60/min limit could never trigger and the endpoint accepted an
 * unbounded stream of unauthenticated DB writes.
 */
final class FileBasedRateLimitMiddlewareTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cimaise_rl_' . uniqid('', true);
        mkdir($this->dir, 0750, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    public function testDefaultModeNeverLimitsAnEndpointThatAlwaysSucceeds(): void
    {
        $mw = new FileBasedRateLimitMiddleware($this->dir, 3, 60, 'default');
        $handler = $this->handler(204);

        for ($i = 0; $i < 10; $i++) {
            $status = $mw->process($this->request(), $handler)->getStatusCode();
            self::assertSame(204, $status, "request #$i must pass in outcome-based mode");
        }
    }

    public function testCountAllModeLimitsAfterMaxRequestsWhateverTheOutcome(): void
    {
        $mw = new FileBasedRateLimitMiddleware($this->dir, 3, 60, 'throttle', true);
        $handler = $this->handler(204);

        for ($i = 0; $i < 3; $i++) {
            self::assertSame(204, $mw->process($this->request(), $handler)->getStatusCode());
        }

        $blocked = $mw->process($this->request(), $handler);
        self::assertSame(429, $blocked->getStatusCode());
        self::assertSame('60', $blocked->getHeaderLine('Retry-After'));
    }

    public function testCountAllModeIsKeyedPerClientIp(): void
    {
        $mw = new FileBasedRateLimitMiddleware($this->dir, 1, 60, 'throttle', true);
        $handler = $this->handler(204);

        self::assertSame(204, $mw->process($this->request('10.0.0.1'), $handler)->getStatusCode());
        self::assertSame(429, $mw->process($this->request('10.0.0.1'), $handler)->getStatusCode());
        self::assertSame(204, $mw->process($this->request('10.0.0.2'), $handler)->getStatusCode());
    }

    public function testCountAllModeStripsTheInternalAuthResultHeader(): void
    {
        $mw = new FileBasedRateLimitMiddleware($this->dir, 5, 60, 'throttle', true);
        $handler = new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Response(200))->withHeader('X-Auth-Result', 'success');
            }
        };

        $response = $mw->process($this->request(), $handler);
        self::assertFalse($response->hasHeader('X-Auth-Result'));
    }

    private function request(string $ip = '203.0.113.7'): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest(
            'POST',
            'http://example.test/api/analytics/track',
            ['REMOTE_ADDR' => $ip]
        );
    }

    private function handler(int $status): RequestHandlerInterface
    {
        return new class ($status) implements RequestHandlerInterface {
            public function __construct(private readonly int $status)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response($this->status);
            }
        };
    }
}
