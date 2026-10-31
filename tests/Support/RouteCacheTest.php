<?php

declare(strict_types=1);

use App\Support\RouteCache;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class RouteCacheTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cimaise-routes-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    /** @param string[] $paths */
    private function app(array $paths): \Slim\App
    {
        $app = AppFactory::create();
        foreach ($paths as $path) {
            $app->get($path, function (Request $request, Response $response) use ($path): Response {
                $response->getBody()->write('route:' . $path);
                return $response;
            });
        }
        return $app;
    }

    public function testCachedDispatcherServesTheRightRouteOnColdAndWarmRuns(): void
    {
        foreach (['cold', 'warm'] as $run) {
            $app = $this->app(['/a', '/b/{id}', '/c']);
            $file = RouteCache::configure($app->getRouteCollector(), $this->dir);
            self::assertNotNull($file);
            $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/b/7'));
            self::assertSame('route:/b/{id}', (string) $response->getBody(), $run);
            self::assertFileExists($file, $run);
        }
    }

    public function testAChangedRouteSetUsesANewCacheFile(): void
    {
        $before = RouteCache::configure($this->app(['/a', '/c'])->getRouteCollector(), $this->dir);
        $same = RouteCache::configure($this->app(['/a', '/c'])->getRouteCollector(), $this->dir);
        $plugin = RouteCache::configure($this->app(['/a', '/plugin', '/c'])->getRouteCollector(), $this->dir);

        self::assertSame($before, $same);
        self::assertNotSame($before, $plugin, 'Route identifiers shift when a plugin adds routes: never reuse the old table');

        // With the new set, /c is route2 — it must still dispatch to /c.
        $app = $this->app(['/a', '/plugin', '/c']);
        RouteCache::configure($app->getRouteCollector(), $this->dir);
        self::assertSame('route:/c', (string) $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/c'))->getBody());
    }
}
