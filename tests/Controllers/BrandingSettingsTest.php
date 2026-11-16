<?php
declare(strict_types=1);

use App\Controllers\Admin\SettingsController;
use App\Services\SettingsService;
use App\Support\Database;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Views\Twig;

final class BrandingSettingsTest extends TestCase
{
    private Database $db;
    private mixed $cache;
    private array $session;
    private string $scriptName;

    protected function setUp(): void
    {
        $this->cache = (new ReflectionProperty(SettingsService::class, 'cache'))->getValue();
        $this->session = $_SESSION ?? [];
        $this->scriptName = $_SERVER['SCRIPT_NAME'];
        $_SESSION = ['csrf' => 'branding-test'];
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $this->db = new Database(database: ':memory:', isSqlite: true);
        $this->db->pdo()->exec(file_get_contents(dirname(__DIR__, 2) . '/database/schema.sqlite.sql'));
        $settings = new SettingsService($this->db);
        (new ReflectionProperty(SettingsService::class, 'cache'))->setValue(null, $settings->defaults());
        $settings->setMany([
            'site.logo' => '/media/light.png', 'site.logo_dark' => '/media/dark.png',
            'site.logo_type' => 'image', 'site.favicon_source' => '/media/icon.png',
            'site.title' => 'Keep this title', 'image.variants_async' => true,
            'maintenance.enabled' => true, 'cache.storage_backend' => 'database',
        ]);
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(SettingsService::class, 'cache'))->setValue(null, $this->cache);
        $_SESSION = $this->session;
        $_SERVER['SCRIPT_NAME'] = $this->scriptName;
    }

    private function controller(): SettingsController
    {
        return new class($this->db, Twig::create(dirname(__DIR__, 2) . '/app/Views')) extends SettingsController {
            protected function ensureSession(): void {}
            protected function getBasePath(): string { return ''; }
        };
    }

    public function testBrandingSaveOnlyUpdatesItsOwnSettingsAndAllowsRemoval(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/admin/branding')->withParsedBody([
            'csrf' => 'branding-test', 'logo_type' => 'text', 'site_logo' => '',
            'site_logo_dark' => '', 'favicon_source' => '', 'site_title' => 'Must be ignored',
        ]);
        $response = $this->controller()->saveBranding($request, new Response());
        self::assertSame('/admin/branding?saved=1', $response->getHeaderLine('Location'));
        $settings = new SettingsService($this->db);
        self::assertNull($settings->get('site.logo'));
        self::assertNull($settings->get('site.logo_dark'));
        self::assertNull($settings->get('site.favicon_source'));
        self::assertSame('text', $settings->get('site.logo_type'));
        self::assertSame('Keep this title', $settings->get('site.title'));
        self::assertTrue($settings->get('image.variants_async'));
        self::assertTrue($settings->get('maintenance.enabled'));
    }

    public function testGeneralSettingsSavePreservesAbsentBrandingFields(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/admin/settings')->withParsedBody([
            'csrf' => 'branding-test', 'site_title' => 'Updated general title',
            'date_format' => 'Y-m-d', 'compression_type' => 'auto',
        ]);
        self::assertSame('/admin/settings?saved=1', $this->controller()->save($request, new Response())->getHeaderLine('Location'));
        $settings = new SettingsService($this->db);
        self::assertSame('/media/light.png', $settings->get('site.logo'));
        self::assertSame('/media/dark.png', $settings->get('site.logo_dark'));
        self::assertSame('/media/icon.png', $settings->get('site.favicon_source'));
        self::assertSame('image', $settings->get('site.logo_type'));
    }

    public function testInvalidCsrfCannotChangeBranding(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('POST', '/admin/branding')
            ->withParsedBody(['csrf' => 'invalid', 'logo_type' => 'text', 'site_logo' => '']);
        self::assertSame('/admin/branding', $this->controller()->saveBranding($request, new Response())->getHeaderLine('Location'));
        self::assertSame('/media/light.png', (new SettingsService($this->db))->get('site.logo'));
    }
}
