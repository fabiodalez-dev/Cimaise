<?php

declare(strict_types=1);

use App\Middlewares\SecurityHeadersMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Security headers must be stamped on every response the middleware wraps —
 * including error statuses — and the CSP nonce handed to the inner handler
 * must be the one the emitted policy carries. (public/index.php registers the
 * middleware outside the error middleware for exactly that reason: the 404/500
 * pages rendered by the error handlers used to leave without any header.)
 */
final class SecurityHeadersMiddlewareTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $serverSnapshot = [];

    protected function setUp(): void
    {
        foreach (['HTTPS', 'SERVER_PORT', 'HTTP_X_FORWARDED_PROTO'] as $k) {
            $this->serverSnapshot[$k] = $_SERVER[$k] ?? null;
            unset($_SERVER[$k]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->serverSnapshot as $k => $v) {
            if ($v === null) {
                unset($_SERVER[$k]);
            } else {
                $_SERVER[$k] = $v;
            }
        }
    }

    /**
     * @dataProvider statuses
     */
    public function testHeadersArePresentWhateverTheStatus(int $status): void
    {
        $mw = new SecurityHeadersMiddleware();
        $response = $mw->process($this->request('/nope'), $this->handler($status));

        self::assertSame($status, $response->getStatusCode());
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        self::assertSame('strict-origin-when-cross-origin', $response->getHeaderLine('Referrer-Policy'));
        self::assertNotSame('', $response->getHeaderLine('Permissions-Policy'));
        self::assertStringContainsString("frame-ancestors 'none'", $response->getHeaderLine('Content-Security-Policy'));
    }

    /** @return array<string, array{int}> */
    public static function statuses(): array
    {
        return [
            'ok' => [200],
            'not found' => [404],
            'method not allowed' => [405],
            'server error' => [500],
        ];
    }

    public function testNonceGivenToHandlerMatchesEmittedPolicy(): void
    {
        $seen = null;
        $handler = new class ($seen) implements RequestHandlerInterface {
            public function __construct(private ?string &$seen)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->seen = (string) $request->getAttribute('csp_nonce');

                return new Response(404);
            }
        };

        $response = (new SecurityHeadersMiddleware())->process($this->request('/missing'), $handler);

        self::assertNotNull($seen);
        self::assertNotSame('', $seen);
        self::assertSame($seen, SecurityHeadersMiddleware::getNonce());
        self::assertStringContainsString("'nonce-{$seen}'", $response->getHeaderLine('Content-Security-Policy'));
    }

    public function testAdminRoutesGetTheAdminPolicyWithoutNonce(): void
    {
        $response = (new SecurityHeadersMiddleware())->process($this->request('/admin/albums'), $this->handler(200));
        $csp = $response->getHeaderLine('Content-Security-Policy');

        self::assertStringContainsString("'unsafe-inline'", $csp);
        self::assertStringNotContainsString('nonce-', $csp);
    }

    public function testCspIsOmittedOn304ButOtherHeadersStay(): void
    {
        $response = (new SecurityHeadersMiddleware())->process($this->request('/'), $this->handler(304));

        self::assertFalse($response->hasHeader('Content-Security-Policy'));
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
    }

    public function testHstsOnlyOverHttps(): void
    {
        $plain = (new SecurityHeadersMiddleware())->process($this->request('/'), $this->handler(200));
        self::assertFalse($plain->hasHeader('Strict-Transport-Security'));

        $_SERVER['HTTPS'] = 'on';
        $secure = (new SecurityHeadersMiddleware())->process($this->request('/'), $this->handler(200));
        self::assertStringContainsString('max-age=31536000', $secure->getHeaderLine('Strict-Transport-Security'));
    }

    private function request(string $path): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('GET', 'http://example.test' . $path);
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
