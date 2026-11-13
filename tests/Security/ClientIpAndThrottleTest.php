<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Middlewares\FileBasedRateLimitMiddleware;
use App\Middlewares\RateLimitMiddleware;
use App\Support\ClientIp;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/** Regressions for audit findings C4, C5, C6 and the leftmost-XFF lead. */
final class ClientIpAndThrottleTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cimaise_throttle_' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0777, true);
        putenv('TRUSTED_PROXIES');
        putenv('APP_ENV=production');
        unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);
    }

    protected function tearDown(): void
    {
        putenv('TRUSTED_PROXIES');
        putenv('TRUST_X_REAL_IP');
        putenv('APP_ENV');
        unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    public function testCidrEntriesAreHonoured(): void
    {
        putenv('TRUSTED_PROXIES=10.0.0.0/8,172.16.0.0/12,fd00::/8');
        self::assertTrue(ClientIp::isTrustedProxy('172.18.0.2'));
        self::assertTrue(ClientIp::isTrustedProxy('10.200.1.1'));
        self::assertTrue(ClientIp::isTrustedProxy('fd12::1'));
        self::assertFalse(ClientIp::isTrustedProxy('172.32.0.1'));
        self::assertFalse(ClientIp::isTrustedProxy('203.0.113.10'));
    }

    public function testMalformedPrefixNeverWidensTrust(): void
    {
        putenv('TRUSTED_PROXIES=10.0.0.0/,10.0.0.0/abc,10.0.0.0/99');
        self::assertFalse(ClientIp::isTrustedProxy('10.1.1.1'));
        self::assertFalse(ClientIp::isTrustedProxy('203.0.113.10'));
    }

    public function testDotenvOnlyValueIsRead(): void
    {
        $_ENV['TRUSTED_PROXIES'] = '172.18.0.2';
        self::assertTrue(ClientIp::isTrustedProxy('172.18.0.2'));
    }

    public function testRightmostUntrustedHopIsTheClient(): void
    {
        putenv('TRUSTED_PROXIES=172.16.0.0/12');
        $ip = ClientIp::resolve([
            'REMOTE_ADDR' => '172.18.0.2',
            'HTTP_X_FORWARDED_FOR' => '192.0.2.77, 203.0.113.10',
            'HTTP_CF_CONNECTING_IP' => '192.0.2.88',
        ]);
        self::assertSame('203.0.113.10', $ip);
        self::assertSame('198.51.100.5', ClientIp::resolve(['REMOTE_ADDR' => '198.51.100.5', 'HTTP_X_FORWARDED_FOR' => '1.1.1.1']));
    }

    public function testRealIpIsAnOptInFallbackOnlyFromATrustedPeer(): void
    {
        putenv('TRUSTED_PROXIES=172.16.0.0/12');
        // Default: ignored (a pass-through proxy would let clients spoof it).
        self::assertSame('172.18.0.2', ClientIp::resolve(['REMOTE_ADDR' => '172.18.0.2', 'HTTP_X_REAL_IP' => '203.0.113.10']));
        putenv('TRUST_X_REAL_IP=1');
        self::assertSame('203.0.113.10', ClientIp::resolve(['REMOTE_ADDR' => '172.18.0.2', 'HTTP_X_REAL_IP' => '203.0.113.10']));
        self::assertSame('198.51.100.5', ClientIp::resolve(['REMOTE_ADDR' => '198.51.100.5', 'HTTP_X_REAL_IP' => '203.0.113.10']));
        self::assertSame('203.0.113.10', ClientIp::resolve(['REMOTE_ADDR' => '172.18.0.2', 'HTTP_X_FORWARDED_FOR' => '203.0.113.10', 'HTTP_X_REAL_IP' => '192.0.2.1']));
    }

    public function testLoginBucketIsSharedAcrossPathSpellings(): void
    {
        $mw = new FileBasedRateLimitMiddleware($this->dir, 5, 600, 'login');
        $spellings = ['/admin/login', '/admin/l%6Fgin', '/admin/logi%6E', '/%61dmin/login', '/admin/l%6Fgin', '/admin/login'];
        $statuses = [];
        foreach ($spellings as $path) {
            $statuses[] = $mw->process($this->post($path, '203.0.113.10'), $this->failedLogin())->getStatusCode();
        }
        self::assertSame([200, 200, 200, 200, 200, 429], $statuses);
    }

    public function testSpoofedForwardedHopsDoNotMintBuckets(): void
    {
        putenv('TRUSTED_PROXIES=172.18.0.2');
        $mw = new FileBasedRateLimitMiddleware($this->dir, 5, 600, 'login');
        $statuses = [];
        for ($n = 1; $n <= 6; $n++) {
            $req = $this->post('/admin/login', '172.18.0.2', [
                'HTTP_X_FORWARDED_FOR' => "192.0.2.$n, 203.0.113.10",
                'HTTP_CF_CONNECTING_IP' => "192.0.2.$n",
            ]);
            $statuses[] = $mw->process($req, $this->failedLogin())->getStatusCode();
        }
        self::assertSame(429, end($statuses));
    }

    public function testCidrProxyDoesNotCollapseAllClientsIntoOneBucket(): void
    {
        putenv('TRUSTED_PROXIES=172.16.0.0/12');
        $mw = new FileBasedRateLimitMiddleware($this->dir, 5, 600, 'login');
        for ($n = 0; $n < 5; $n++) {
            $mw->process($this->post('/admin/login', '172.18.0.2', ['HTTP_X_FORWARDED_FOR' => '203.0.113.10']), $this->failedLogin());
        }
        $other = $mw->process($this->post('/admin/login', '172.18.0.2', ['HTTP_X_FORWARDED_FOR' => '198.51.100.20']), $this->failedLogin());
        self::assertSame(200, $other->getStatusCode());
    }

    public function testAlbumUnlockBucketIsSharedAcrossSlugSpellings(): void
    {
        $mw = new RateLimitMiddleware(5, 600, false, $this->dir);
        $handler = new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Response(302))->withHeader('Location', '/album/pwalbum?error=1');
            }
        };
        $statuses = [];
        foreach (['pwalbum', 'pw%61lbum', 'pwa%6Cbum', 'PWALBUM', 'pwalbu%6D', 'pwalbum'] as $slug) {
            $statuses[] = $mw->process($this->post("/album/$slug/unlock", '203.0.113.10'), $handler)->getStatusCode();
        }
        self::assertSame(429, end($statuses));
    }

    /** @param array<string, string> $extra */
    private function post(string $path, string $remote, array $extra = []): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', 'http://localhost' . $path, ['REMOTE_ADDR' => $remote] + $extra);
    }

    private function failedLogin(): RequestHandlerInterface
    {
        return new class () implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Response(200))->withHeader('X-Auth-Result', 'failed');
            }
        };
    }
}
