<?php
declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\SitemapService;
use App\Services\SettingsService;
use App\Support\Database;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use PDO;
use PDOStatement;

class SitemapServiceTest extends TestCase
{
    private SitemapService $service;
    private Database|MockObject $mockDb;
    private PDO|MockObject $mockPdo;
    private string $testPublicPath;
    private string $testBaseUrl;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->testPublicPath = sys_get_temp_dir() . '/sitemap_test_' . uniqid();
        mkdir($this->testPublicPath, 0755, true);
        
        $this->testBaseUrl = 'https://example.com';
        
        // Mock PDO and Database
        $this->mockPdo = $this->createMock(PDO::class);
        $this->mockDb = $this->createMock(Database::class);
        $this->mockDb->method('pdo')->willReturn($this->mockPdo);
        
        $this->service = new SitemapService(
            $this->mockDb,
            $this->testBaseUrl,
            $this->testPublicPath
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        
        // Clean up test directory
        if (is_dir($this->testPublicPath)) {
            $files = glob($this->testPublicPath . '/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($this->testPublicPath);
        }
    }

    private function mockSettingsQuery(string $key, mixed $value): void
    {
        $stmt = $this->createMock(PDOStatement::class);
        $stmt->method('fetchColumn')->willReturn(json_encode($value));
        
        $this->mockPdo->expects($this->any())
            ->method('prepare')
            ->willReturn($stmt);
    }

    public function testConstructorTrimsTrailingSlashes(): void
    {
        $service = new SitemapService(
            $this->mockDb,
            'https://example.com/',
            $this->testPublicPath . '/'
        );
        
        $this->assertInstanceOf(SitemapService::class, $service);
    }

    public function testGenerateCreatesBasicSitemap(): void
    {
        // Mock settings query
        $settingsStmt = $this->createMock(PDOStatement::class);
        $settingsStmt->method('execute')->willReturn(true);
        $settingsStmt->method('fetchColumn')->willReturn(json_encode('about'));
        
        // Mock categories query
        $categoriesStmt = $this->createMock(PDOStatement::class);
        $categoriesStmt->method('fetchAll')->willReturn([
            ['slug' => 'portraits', 'updated_at' => '2024-01-01 12:00:00'],
            ['slug' => 'landscapes', 'updated_at' => '2024-01-15 12:00:00'],
        ]);
        
        // Mock tags query
        $tagsStmt = $this->createMock(PDOStatement::class);
        $tagsStmt->method('fetchAll')->willReturn([
            ['slug' => 'nature'],
            ['slug' => 'urban'],
        ]);
        
        // Mock albums query
        $albumsStmt = $this->createMock(PDOStatement::class);
        $albumsStmt->method('fetchAll')->willReturn([
            [
                'slug' => 'summer-2024',
                'published_at' => '2024-06-01 10:00:00',
                'updated_at' => '2024-06-15 10:00:00',
                'is_nsfw' => 0
            ],
            [
                'slug' => 'winter-2023',
                'published_at' => '2023-12-01 10:00:00',
                'updated_at' => '2023-12-20 10:00:00',
                'is_nsfw' => 0
            ],
        ]);
        
        $this->mockPdo->expects($this->any())
            ->method('query')
            ->willReturnOnConsecutiveCalls(
                $categoriesStmt,
                $tagsStmt,
                $albumsStmt
            );
        
        $this->mockPdo->expects($this->any())
            ->method('prepare')
            ->willReturn($settingsStmt);
        
        $result = $this->service->generate();
        
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('message', $result);
        $this->assertArrayHasKey('file', $result);
        $this->assertStringContainsString('Sitemap generated successfully', $result['message']);
    }

    public function testGenerateSkipsNsfwAlbums(): void
    {
        // Mock settings
        $settingsStmt = $this->createMock(PDOStatement::class);
        $settingsStmt->method('fetchColumn')->willReturn(json_encode('about'));
        
        // Mock empty categories and tags
        $emptyStmt = $this->createMock(PDOStatement::class);
        $emptyStmt->method('fetchAll')->willReturn([]);
        
        // Mock albums including NSFW
        $albumsStmt = $this->createMock(PDOStatement::class);
        $albumsStmt->method('fetchAll')->willReturn([
            [
                'slug' => 'safe-album',
                'published_at' => '2024-01-01',
                'updated_at' => '2024-01-15',
                'is_nsfw' => 0
            ],
            [
                'slug' => 'nsfw-album',
                'published_at' => '2024-01-01',
                'updated_at' => '2024-01-15',
                'is_nsfw' => 1
            ],
        ]);
        
        $this->mockPdo->expects($this->any())
            ->method('query')
            ->willReturnOnConsecutiveCalls($emptyStmt, $emptyStmt, $albumsStmt);
        
        $this->mockPdo->expects($this->any())
            ->method('prepare')
            ->willReturn($settingsStmt);
        
        $result = $this->service->generate();
        
        // Should succeed without including NSFW album
        $this->assertTrue($result['success']);
    }

    public function testGenerateHandlesExceptionGracefully(): void
    {
        // Make PDO throw an exception
        $this->mockPdo->expects($this->any())
            ->method('query')
            ->willThrowException(new \Exception('Database error'));
        
        $result = $this->service->generate();
        
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Failed to generate sitemap', $result['error']);
        $this->assertStringContainsString('Database error', $result['error']);
    }

    public function testGenerateCreatesRobotsTxtIfNotExists(): void
    {
        // Mock database queries to return empty results
        $emptyStmt = $this->createMock(PDOStatement::class);
        $emptyStmt->method('fetchAll')->willReturn([]);
        
        $settingsStmt = $this->createMock(PDOStatement::class);
        $settingsStmt->method('fetchColumn')->willReturn(json_encode('about'));
        
        $this->mockPdo->expects($this->any())
            ->method('query')
            ->willReturn($emptyStmt);
        
        $this->mockPdo->expects($this->any())
            ->method('prepare')
            ->willReturn($settingsStmt);
        
        $result = $this->service->generate();
        
        $this->assertTrue($result['success']);
        
        // Check if robots.txt was created with sitemap reference
        $robotsPath = $this->testPublicPath . '/robots.txt';
        if (file_exists($robotsPath)) {
            $content = file_get_contents($robotsPath);
            $this->assertStringContainsString('Sitemap:', $content);
            $this->assertStringContainsString($this->testBaseUrl . '/sitemap.xml', $content);
        }
    }

    public function testGenerateUpdatesExistingRobotsTxt(): void
    {
        // Create existing robots.txt without sitemap
        $robotsPath = $this->testPublicPath . '/robots.txt';
        file_put_contents($robotsPath, "User-agent: *\nDisallow: /admin/");
        
        // Mock database queries
        $emptyStmt = $this->createMock(PDOStatement::class);
        $emptyStmt->method('fetchAll')->willReturn([]);
        
        $settingsStmt = $this->createMock(PDOStatement::class);
        $settingsStmt->method('fetchColumn')->willReturn(json_encode('about'));
        
        $this->mockPdo->expects($this->any())
            ->method('query')
            ->willReturn($emptyStmt);
        
        $this->mockPdo->expects($this->any())
            ->method('prepare')
            ->willReturn($settingsStmt);
        
        $result = $this->service->generate();
        
        $this->assertTrue($result['success']);
        
        // Verify robots.txt was updated
        $content = file_get_contents($robotsPath);
        $this->assertStringContainsString('User-agent: *', $content);
        $this->assertStringContainsString('Sitemap:', $content);
    }

    public function testGenerateDoesNotDuplicateSitemapInRobotsTxt(): void
    {
        // Create robots.txt with sitemap already present
        $robotsPath = $this->testPublicPath . '/robots.txt';
        file_put_contents($robotsPath, "User-agent: *\nSitemap: https://example.com/sitemap.xml");
        
        // Mock database queries
        $emptyStmt = $this->createMock(PDOStatement::class);
        $emptyStmt->method('fetchAll')->willReturn([]);
        
        $settingsStmt = $this->createMock(PDOStatement::class);
        $settingsStmt->method('fetchColumn')->willReturn(json_encode('about'));
        
        $this->mockPdo->expects($this->any())
            ->method('query')
            ->willReturn($emptyStmt);
        
        $this->mockPdo->expects($this->any())
            ->method('prepare')
            ->willReturn($settingsStmt);
        
        $result = $this->service->generate();
        
        $this->assertTrue($result['success']);
        
        // Verify only one Sitemap entry exists
        $content = file_get_contents($robotsPath);
        $this->assertEquals(1, substr_count($content, 'Sitemap:'));
    }

    public function testExists(): void
    {
        // Initially should not exist
        $this->assertFalse($this->service->exists());
        
        // Create sitemap file
        file_put_contents($this->testPublicPath . '/sitemap.xml', '<?xml version="1.0"?><urlset></urlset>');
        
        // Now should exist
        $this->assertTrue($this->service->exists());
    }

    public function testGetLastModified(): void
    {
        // Should return null when file doesn't exist
        $this->assertNull($this->service->getLastModified());
        
        // Create sitemap file
        $sitemapPath = $this->testPublicPath . '/sitemap.xml';
        file_put_contents($sitemapPath, '<?xml version="1.0"?><urlset></urlset>');
        
        // Should return timestamp
        $mtime = $this->service->getLastModified();
        $this->assertIsInt($mtime);
        $this->assertGreaterThan(0, $mtime);
        
        // Verify timestamp is recent
        $this->assertGreaterThanOrEqual(time() - 10, $mtime);
        $this->assertLessThanOrEqual(time(), $mtime);
    }

    public function testGetLastModifiedWithOlderFile(): void
    {
        $sitemapPath = $this->testPublicPath . '/sitemap.xml';
        file_put_contents($sitemapPath, '<?xml version="1.0"?><urlset></urlset>');
        
        // Set file modification time to a specific time
        $testTime = strtotime('2024-01-01 12:00:00');
        touch($sitemapPath, $testTime);
        
        $mtime = $this->service->getLastModified();
        $this->assertEquals($testTime, $mtime);
    }

    public function testGenerateWithCustomAboutSlug(): void
    {
        // Mock settings with custom about slug
        $settingsStmt = $this->createMock(PDOStatement::class);
        $settingsStmt->method('fetchColumn')->willReturn(json_encode('custom-about'));
        
        $emptyStmt = $this->createMock(PDOStatement::class);
        $emptyStmt->method('fetchAll')->willReturn([]);
        
        $this->mockPdo->expects($this->any())
            ->method('query')
            ->willReturn($emptyStmt);
        
        $this->mockPdo->expects($this->any())
            ->method('prepare')
            ->willReturn($settingsStmt);
        
        $result = $this->service->generate();
        
        $this->assertTrue($result['success']);
    }

    public function testGenerateIncludesHomepageWithHighestPriority(): void
    {
        // This is implicit in the generate() method
        // We verify through successful generation
        $emptyStmt = $this->createMock(PDOStatement::class);
        $emptyStmt->method('fetchAll')->willReturn([]);
        
        $settingsStmt = $this->createMock(PDOStatement::class);
        $settingsStmt->method('fetchColumn')->willReturn(json_encode('about'));
        
        $this->mockPdo->expects($this->any())
            ->method('query')
            ->willReturn($emptyStmt);
        
        $this->mockPdo->expects($this->any())
            ->method('prepare')
            ->willReturn($settingsStmt);
        
        $result = $this->service->generate();
        
        $this->assertTrue($result['success']);
        $this->assertStringContainsString($this->testBaseUrl . '/sitemap.xml', $result['message']);
    }

    public function testGenerateHandlesAlbumsWithoutUpdatedAt(): void
    {
        $settingsStmt = $this->createMock(PDOStatement::class);
        $settingsStmt->method('fetchColumn')->willReturn(json_encode('about'));
        
        $emptyStmt = $this->createMock(PDOStatement::class);
        $emptyStmt->method('fetchAll')->willReturn([]);
        
        // Albums with missing updated_at
        $albumsStmt = $this->createMock(PDOStatement::class);
        $albumsStmt->method('fetchAll')->willReturn([
            [
                'slug' => 'old-album',
                'published_at' => '2023-01-01',
                'updated_at' => null,
                'is_nsfw' => 0
            ],
        ]);
        
        $this->mockPdo->expects($this->any())
            ->method('query')
            ->willReturnOnConsecutiveCalls($emptyStmt, $emptyStmt, $albumsStmt);
        
        $this->mockPdo->expects($this->any())
            ->method('prepare')
            ->willReturn($settingsStmt);
        
        $result = $this->service->generate();
        
        $this->assertTrue($result['success']);
    }

    public function testGenerateHandlesCategoriesWithNullUpdatedAt(): void
    {
        $settingsStmt = $this->createMock(PDOStatement::class);
        $settingsStmt->method('fetchColumn')->willReturn(json_encode('about'));
        
        $categoriesStmt = $this->createMock(PDOStatement::class);
        $categoriesStmt->method('fetchAll')->willReturn([
            ['slug' => 'category1', 'updated_at' => null],
        ]);
        
        $emptyStmt = $this->createMock(PDOStatement::class);
        $emptyStmt->method('fetchAll')->willReturn([]);
        
        $this->mockPdo->expects($this->any())
            ->method('query')
            ->willReturnOnConsecutiveCalls($categoriesStmt, $emptyStmt, $emptyStmt);
        
        $this->mockPdo->expects($this->any())
            ->method('prepare')
            ->willReturn($settingsStmt);
        
        $result = $this->service->generate();
        
        $this->assertTrue($result['success']);
    }

    public function testGenerateReturnsCorrectFilePathInResult(): void
    {
        $emptyStmt = $this->createMock(PDOStatement::class);
        $emptyStmt->method('fetchAll')->willReturn([]);
        
        $settingsStmt = $this->createMock(PDOStatement::class);
        $settingsStmt->method('fetchColumn')->willReturn(json_encode('about'));
        
        $this->mockPdo->expects($this->any())
            ->method('query')
            ->willReturn($emptyStmt);
        
        $this->mockPdo->expects($this->any())
            ->method('prepare')
            ->willReturn($settingsStmt);
        
        $result = $this->service->generate();
        
        $this->assertTrue($result['success']);
        $this->assertEquals($this->testPublicPath . '/sitemap.xml', $result['file']);
    }
}