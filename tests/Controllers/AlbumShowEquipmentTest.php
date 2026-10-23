<?php

declare(strict_types=1);

use App\Controllers\Admin\AlbumsController;
use App\Services\SettingsService;
use App\Support\Database;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Slim\Views\Twig;

/**
 * The equipment toggle lives in albums.show_equipment only. A per-album
 * settings row duplicated it, was written inside the album transaction (so
 * the shared settings cache saw uncommitted state) and grew the settings
 * table — hashed into every HTML validator — by one row per album.
 */
final class AlbumShowEquipmentTest extends TestCase
{
    private Database $db;
    private mixed $settingsSnapshot;
    private array $session;
    private string $scriptName;

    protected function setUp(): void
    {
        $this->session = $_SESSION ?? [];
        $this->scriptName = $_SERVER['SCRIPT_NAME'];
        $_SESSION = ['csrf' => 'album-equipment-test'];
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $property = new ReflectionProperty(SettingsService::class, 'cache');
        $this->settingsSnapshot = $property->getValue();
        $this->db = new Database(database: ':memory:', isSqlite: true);
        $this->db->pdo()->exec((string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sqlite.sql'));
        $settings = new SettingsService($this->db);
        $property->setValue(null, array_replace($settings->defaults(), [
            'cache.auto_warm' => false, 'cache.storage_backend' => 'database', 'seo.sitemap_enabled' => false,
        ]));
        $this->db->pdo()->exec("INSERT INTO categories(id,name,slug) VALUES(876550,'Fixture','fixture-equipment')");
    }

    protected function tearDown(): void
    {
        (new ReflectionProperty(SettingsService::class, 'cache'))->setValue(null, $this->settingsSnapshot);
        $_SESSION = $this->session;
        $_SERVER['SCRIPT_NAME'] = $this->scriptName;
    }

    private function controller(): AlbumsController
    {
        return new class($this->db, Twig::create(dirname(__DIR__, 2) . '/app/Views')) extends AlbumsController {
            protected function ensureSession(): void {}
        };
    }

    private function request(string $path, array $body): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', $path)
            ->withParsedBody($body + ['csrf' => 'album-equipment-test', 'category_id' => '876550']);
    }

    private function equipmentSettingRows(): int
    {
        return (int) $this->db->pdo()->query("SELECT COUNT(*) FROM settings WHERE key LIKE 'album.%.show_equipment'")->fetchColumn();
    }

    public function testCreateAndEditStoreTheFlagOnlyOnTheAlbum(): void
    {
        $this->controller()->store($this->request('/admin/albums', ['title' => 'Equipment test', 'slug' => 'equipment-test']), new Response());
        $id = (int) $this->db->pdo()->query("SELECT id FROM albums WHERE slug = 'equipment-test'")->fetchColumn();
        self::assertGreaterThan(0, $id, json_encode($_SESSION['flash'] ?? []));
        self::assertSame(0, (int) $this->db->pdo()->query("SELECT show_equipment FROM albums WHERE id = {$id}")->fetchColumn());

        $this->controller()->update($this->request("/admin/albums/{$id}", [
            'title' => 'Equipment test', 'slug' => 'equipment-test', 'show_equipment' => '1',
        ]), new Response(), ['id' => $id]);
        self::assertSame(1, (int) $this->db->pdo()->query("SELECT show_equipment FROM albums WHERE id = {$id}")->fetchColumn(), json_encode($_SESSION['flash'] ?? []));

        self::assertSame(0, $this->equipmentSettingRows());
    }
}
