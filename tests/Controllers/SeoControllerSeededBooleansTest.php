<?php

declare(strict_types=1);

use App\Controllers\Admin\SeoController;
use App\Services\SettingsService;
use App\Support\Database;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Views\Twig;
use Twig\Loader\ArrayLoader;

/**
 * The SEO admin page must show a toggle as ON when the stored value is a JSON
 * boolean (what database/schema.*.sql and database/template.sqlite seed) and
 * not only when it is the "true" STRING this form itself saves.
 *
 * Regression: a strict `=== 'true'` rendered every seeded toggle (schema,
 * breadcrumbs, sitemap, auto alt, lazy-load, preload) as OFF on a fresh
 * install, and the first save then persisted that OFF for all of them.
 */
final class SeoControllerSeededBooleansTest extends TestCase
{
    private string $dbFile;
    private Database $db;

    protected function setUp(): void
    {
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SESSION = ['csrf' => 'test'];
        $this->dbFile = sys_get_temp_dir() . '/cimaise_seo_test_' . uniqid('', true) . '.sqlite';
        $this->db = new Database(null, null, $this->dbFile, null, null, 'utf8mb4', 'utf8mb4_unicode_ci', true);
        $this->db->pdo()->exec('CREATE TABLE settings (`key` TEXT PRIMARY KEY, `value` TEXT, `type` TEXT, updated_at TEXT)');
        // Exactly the seed representation: raw JSON booleans, type "boolean".
        $this->db->pdo()->exec("INSERT INTO settings (`key`, `value`, `type`) VALUES
            ('seo.schema_enabled', 'true', 'boolean'),
            ('seo.breadcrumbs_enabled', 'true', 'boolean'),
            ('seo.sitemap_enabled', 'true', 'boolean'),
            ('seo.image_alt_auto', 'true', 'boolean'),
            ('seo.lazy_load_images', 'true', 'boolean'),
            ('seo.preload_critical_images', 'true', 'boolean'),
            ('seo.local_business_enabled', 'false', 'boolean'),
            ('seo.expose_gps', 'false', 'boolean')");
        // The settings cache is process-wide (file/APCu): never let a previous
        // run, or the live site, bleed into this test — and vice versa.
        (new SettingsService($this->db))->clearCache();
    }

    protected function tearDown(): void
    {
        (new SettingsService($this->db))->clearCache();
        unset($this->db);
        foreach ([$this->dbFile, $this->dbFile . '-wal', $this->dbFile . '-shm'] as $f) {
            if (is_file($f)) {
                @unlink($f); // nosemgrep
            }
        }
    }

    private function renderFlags(): array
    {
        $twig = new Twig(new ArrayLoader([
            'admin/seo/index.twig' => '{{ settings.schema_enabled ? 1 : 0 }},{{ settings.breadcrumbs_enabled ? 1 : 0 }},{{ settings.sitemap_enabled ? 1 : 0 }},{{ settings.image_alt_auto ? 1 : 0 }},{{ settings.lazy_load_images ? 1 : 0 }},{{ settings.preload_critical_images ? 1 : 0 }},{{ settings.local_business_enabled ? 1 : 0 }},{{ settings.expose_gps ? 1 : 0 }}',
        ]));
        $controller = new SeoController($this->db, $twig);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/admin/seo');
        $response = $controller->index($request, new Response());
        return array_map('intval', explode(',', (string) $response->getBody()));
    }

    public function testSeededJsonBooleansRenderAsCheckedToggles(): void
    {
        self::assertSame([1, 1, 1, 1, 1, 1, 0, 0], $this->renderFlags());
    }

    public function testStringBooleansSavedByTheFormStillRender(): void
    {
        // What SeoController::save() persists: "true"/"false" strings.
        $this->db->pdo()->exec("UPDATE settings SET `value` = '\"false\"', `type` = 'string' WHERE `key` = 'seo.schema_enabled'");
        $this->db->pdo()->exec("UPDATE settings SET `value` = '\"true\"', `type` = 'string' WHERE `key` = 'seo.expose_gps'");
        (new SettingsService($this->db))->clearCache();
        self::assertSame([0, 1, 1, 1, 1, 1, 0, 1], $this->renderFlags());
    }
}
