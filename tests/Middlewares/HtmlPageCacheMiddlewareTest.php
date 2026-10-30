<?php

declare(strict_types=1);

use App\Middlewares\CacheMiddleware;
use App\Services\HtmlPageCache;
use App\Services\PageCacheService;
use App\Services\SettingsService;
use App\Support\Database;
use App\Support\LazySession;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

require_once dirname(__DIR__) . '/Support/TempQueryCache.php';

final class HtmlPageCacheMiddlewareTest extends TestCase
{
    private Database $db;
    private SettingsService $settings;
    private HtmlPageCache $html;
    private string $dir;
    private array $session;
    private mixed $settingsSnapshot;
    private string $scriptName;
    private int $renders = 0;
    private Closure $restoreCache;

    protected function setUp(): void
    {
        $this->restoreCache = TempQueryCache::install();
        $this->session = $_SESSION ?? [];
        $this->scriptName = $_SERVER['SCRIPT_NAME'];
        $_SESSION = ['csrf' => str_repeat('ab', 32)];
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $property = new ReflectionProperty(SettingsService::class, 'cache');
        $this->settingsSnapshot = $property->getValue();
        $this->db = new Database(database: ':memory:', isSqlite: true);
        $this->db->pdo()->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sqlite.sql'));
        $this->db->pdo()->exec("INSERT INTO categories (id,name,slug) VALUES (88888,'C','c');
            INSERT INTO albums (id,title,slug,category_id,is_published,excerpt) VALUES (88888,'Cached','cached',88888,1,'x')");
        $this->settings = new SettingsService($this->db);
        $property->setValue(null, $this->settings->defaults() + ['cache.storage_backend' => 'database']);
        (new PageCacheService($this->settings, $this->db))->set(PageCacheService::albumPageKey(88888), ['template_file' => 'a.twig', 'data' => ['v' => 1]]);
        $this->dir = sys_get_temp_dir() . '/cimaise-htmlmw-' . bin2hex(random_bytes(6));
        $this->html = new HtmlPageCache($this->dir);
        (new ReflectionProperty(LazySession::class, 'active'))->setValue(null, true);
    }

    protected function tearDown(): void
    {
        LazySession::reset();
        ($this->restoreCache)();
        (new ReflectionProperty(SettingsService::class, 'cache'))->setValue(null, $this->settingsSnapshot);
        $_SESSION = $this->session;
        $_SERVER['SCRIPT_NAME'] = $this->scriptName;
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    private function request(string $nonce, array $cookies = []): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('GET', 'http://example.test/album/cached')
            ->withCookieParams($cookies)
            ->withAttribute('csp_nonce', $nonce);
    }

    private function handler(string $extra = ''): RequestHandlerInterface
    {
        return new class($this, $extra) implements RequestHandlerInterface {
            public function __construct(private HtmlPageCacheMiddlewareTest $test, private string $extra) {}
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->test->countRender();
                $nonce = (string) $request->getAttribute('csp_nonce');
                $response = new Response();
                $response->getBody()->write('<html><script nonce="' . $nonce . '"></script>' . $this->extra . '</html>');
                return $response->withHeader('Content-Type', 'text/html; charset=UTF-8')->withHeader('X-Robots-Tag', 'index');
            }
        };
    }

    public function countRender(): void
    {
        $this->renders++;
    }

    private function middleware(): CacheMiddleware
    {
        return new CacheMiddleware($this->settings, $this->db, $this->html);
    }

    public function testSecondCookieLessViewIsServedWithoutRendering(): void
    {
        $first = $this->middleware()->process($this->request('NONCE1'), $this->handler());
        $second = $this->middleware()->process($this->request('NONCE2'), $this->handler());

        self::assertSame(1, $this->renders);
        self::assertSame('hit', $second->getHeaderLine('X-Cimaise-Cache'));
        self::assertSame('<html><script nonce="NONCE2"></script></html>', (string) $second->getBody());
        self::assertSame($first->getHeaderLine('ETag'), $second->getHeaderLine('ETag'));
        self::assertSame('index', $second->getHeaderLine('X-Robots-Tag'));
        self::assertStringStartsWith('public', $second->getHeaderLine('Cache-Control'));
    }

    public function testRequestsWithAnyCookieAlwaysRender(): void
    {
        $this->middleware()->process($this->request('N1'), $this->handler());
        $response = $this->middleware()->process($this->request('N2', ['nsfw_consent' => 'signed']), $this->handler());

        self::assertSame(2, $this->renders);
        self::assertSame('', $response->getHeaderLine('X-Cimaise-Cache'));
    }

    public function testPagesEmbeddingTheCsrfTokenAreNeverStored(): void
    {
        $form = '<input name="csrf" value="' . $_SESSION['csrf'] . '">';
        $this->middleware()->process($this->request('N1'), $this->handler($form));
        $this->middleware()->process($this->request('N2'), $this->handler($form));

        self::assertSame(2, $this->renders);
    }

    public function testContentChangeInvalidatesTheStoredHtml(): void
    {
        $this->middleware()->process($this->request('N1'), $this->handler());
        (new PageCacheService($this->settings, $this->db))->set(PageCacheService::albumPageKey(88888), ['template_file' => 'a.twig', 'data' => ['v' => 2]]);
        $response = $this->middleware()->process($this->request('N2'), $this->handler());

        self::assertSame(2, $this->renders);
        self::assertSame('', $response->getHeaderLine('X-Cimaise-Cache'));
    }

    public function testEditedFrontendTextsChangeTheValidator(): void
    {
        $first = $this->middleware()->process($this->request('N1'), $this->handler());
        \App\Services\TranslationService::invalidatePersistentCache();
        $second = $this->middleware()->process($this->request('N2'), $this->handler());

        self::assertSame(2, $this->renders, 'Every page embeds texts: an edit must not be served from the stored HTML');
        self::assertNotSame($first->getHeaderLine('ETag'), $second->getHeaderLine('ETag'), 'Nor revalidated with a 304');
    }

    public function testNotUsedWithoutALazySession(): void
    {
        LazySession::reset();
        $this->middleware()->process($this->request('N1'), $this->handler());
        $this->middleware()->process($this->request('N2'), $this->handler());

        self::assertSame(2, $this->renders);
    }
}
