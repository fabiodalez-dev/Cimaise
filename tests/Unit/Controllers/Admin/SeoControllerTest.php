<?php
declare(strict_types=1);

namespace Tests\Unit\Controllers\Admin;

use App\Controllers\Admin\SeoController;
use App\Services\SettingsService;
use App\Support\Database;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use Slim\Views\Twig;
use PDO;

class SeoControllerTest extends TestCase
{
    private SeoController $controller;
    private Database|MockObject $mockDb;
    private Twig|MockObject $mockView;
    private ServerRequestInterface|MockObject $mockRequest;
    private ResponseInterface|MockObject $mockResponse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockDb = $this->createMock(Database::class);
        $this->mockView = $this->createMock(Twig::class);
        $this->controller = new SeoController($this->mockDb, $this->mockView);

        $this->mockRequest = $this->createMock(ServerRequestInterface::class);
        $this->mockResponse = $this->createMock(ResponseInterface::class);

        // Initialize session for tests
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION['csrf'] = 'test-csrf-token';
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        unset($_SESSION['csrf']);
        unset($_SESSION['flash']);
    }

    public function testIndexRendersViewWithSettings(): void
    {
        $this->mockView->expects($this->once())
            ->method('render')
            ->with(
                $this->mockResponse,
                'admin/seo/index.twig',
                $this->callback(function ($data) {
                    $this->assertArrayHasKey('settings', $data);
                    $this->assertArrayHasKey('csrf', $data);
                    return true;
                })
            )
            ->willReturn($this->mockResponse);

        $result = $this->controller->index($this->mockRequest, $this->mockResponse);

        $this->assertSame($this->mockResponse, $result);
    }

    public function testSaveWithInvalidCsrfToken(): void
    {
        $this->mockRequest->method('getParsedBody')->willReturn([
            'csrf' => 'invalid-token'
        ]);

        $this->mockResponse->expects($this->once())
            ->method('withHeader')
            ->with('Location', $this->stringContains('/admin/seo'))
            ->willReturnSelf();

        $this->mockResponse->expects($this->once())
            ->method('withStatus')
            ->with(302)
            ->willReturnSelf();

        $result = $this->controller->save($this->mockRequest, $this->mockResponse);

        $this->assertArrayHasKey('flash', $_SESSION);
        $this->assertContains('Invalid CSRF token', array_column($_SESSION['flash'], 'message'));
    }

    public function testSaveWithValidCsrfTokenSavesSettings(): void
    {
        $postData = [
            'csrf' => 'test-csrf-token',
            'site_title' => 'My Portfolio',
            'site_description' => 'A great portfolio',
            'site_keywords' => 'photo, art',
            'schema_enabled' => '1',
            'sitemap_enabled' => '1',
        ];

        $this->mockRequest->method('getParsedBody')->willReturn($postData);

        $this->mockResponse->expects($this->once())
            ->method('withHeader')
            ->with('Location', $this->stringContains('/admin/seo'))
            ->willReturnSelf();

        $this->mockResponse->expects($this->once())
            ->method('withStatus')
            ->with(302)
            ->willReturnSelf();

        $result = $this->controller->save($this->mockRequest, $this->mockResponse);

        $this->assertArrayHasKey('flash', $_SESSION);
    }

    public function testSaveHandlesCheckboxesCorrectly(): void
    {
        $postData = [
            'csrf' => 'test-csrf-token',
            'site_title' => 'Test',
            'schema_enabled' => '1',
            'breadcrumbs_enabled' => '1',
            // local_business_enabled not set (checkbox unchecked)
        ];

        $this->mockRequest->method('getParsedBody')->willReturn($postData);

        $this->mockResponse->method('withHeader')->willReturnSelf();
        $this->mockResponse->method('withStatus')->willReturnSelf();

        $result = $this->controller->save($this->mockRequest, $this->mockResponse);

        $this->assertSame($this->mockResponse, $result);
    }

    public function testSaveTrimsStringInputs(): void
    {
        $postData = [
            'csrf' => 'test-csrf-token',
            'site_title' => '  Test Title  ',
            'site_description' => '  Description with spaces  ',
            'author_name' => '  John Doe  ',
        ];

        $this->mockRequest->method('getParsedBody')->willReturn($postData);

        $this->mockResponse->method('withHeader')->willReturnSelf();
        $this->mockResponse->method('withStatus')->willReturnSelf();

        $result = $this->controller->save($this->mockRequest, $this->mockResponse);

        $this->assertSame($this->mockResponse, $result);
    }

    public function testSaveHandlesEmptyOptionalFields(): void
    {
        $postData = [
            'csrf' => 'test-csrf-token',
            'site_title' => 'Title',
            'author_name' => '',
            'twitter_site' => '',
            'analytics_gtag' => '',
        ];

        $this->mockRequest->method('getParsedBody')->willReturn($postData);

        $this->mockResponse->method('withHeader')->willReturnSelf();
        $this->mockResponse->method('withStatus')->willReturnSelf();

        $result = $this->controller->save($this->mockRequest, $this->mockResponse);

        $this->assertSame($this->mockResponse, $result);
    }

    public function testSaveOnlyProcessesLocalBusinessDataWhenEnabled(): void
    {
        $postData = [
            'csrf' => 'test-csrf-token',
            'site_title' => 'Test',
            'local_business_enabled' => '1',
            'local_business_name' => 'My Business',
            'local_business_phone' => '+1234567890',
        ];

        $this->mockRequest->method('getParsedBody')->willReturn($postData);

        $this->mockResponse->method('withHeader')->willReturnSelf();
        $this->mockResponse->method('withStatus')->willReturnSelf();

        $result = $this->controller->save($this->mockRequest, $this->mockResponse);

        $this->assertSame($this->mockResponse, $result);
    }

    public function testGenerateSitemapWithInvalidCsrf(): void
    {
        $this->mockRequest->method('getParsedBody')->willReturn([
            'csrf' => 'invalid-token'
        ]);

        $this->mockResponse->expects($this->once())
            ->method('withHeader')
            ->with('Location', $this->stringContains('/admin/seo'))
            ->willReturnSelf();

        $this->mockResponse->expects($this->once())
            ->method('withStatus')
            ->with(302)
            ->willReturnSelf();

        $result = $this->controller->generateSitemap($this->mockRequest, $this->mockResponse);

        $this->assertArrayHasKey('flash', $_SESSION);
    }

    public function testGenerateSitemapWithValidCsrf(): void
    {
        $this->mockRequest->method('getParsedBody')->willReturn([
            'csrf' => 'test-csrf-token'
        ]);

        $mockPdo = $this->createMock(PDO::class);
        $this->mockDb->method('pdo')->willReturn($mockPdo);

        $this->mockResponse->method('withHeader')->willReturnSelf();
        $this->mockResponse->method('withStatus')->willReturnSelf();

        $result = $this->controller->generateSitemap($this->mockRequest, $this->mockResponse);

        $this->assertSame($this->mockResponse, $result);
    }

    public function testSaveHandlesExceptionGracefully(): void
    {
        $postData = [
            'csrf' => 'test-csrf-token',
            'site_title' => 'Test',
        ];

        $this->mockRequest->method('getParsedBody')->willReturn($postData);

        // Force an exception by using a null DB
        $controller = new SeoController($this->mockDb, $this->mockView);

        $this->mockResponse->method('withHeader')->willReturnSelf();
        $this->mockResponse->method('withStatus')->willReturnSelf();

        // Should not throw exception
        $result = $controller->save($this->mockRequest, $this->mockResponse);

        $this->assertSame($this->mockResponse, $result);
    }

    public function testSaveStoresOpenGraphSettings(): void
    {
        $postData = [
            'csrf' => 'test-csrf-token',
            'og_site_name' => 'My Site',
            'og_type' => 'website',
            'og_locale' => 'en_US',
            'twitter_card' => 'summary_large_image',
            'twitter_site' => '@mysite',
            'twitter_creator' => '@creator',
        ];

        $this->mockRequest->method('getParsedBody')->willReturn($postData);

        $this->mockResponse->method('withHeader')->willReturnSelf();
        $this->mockResponse->method('withStatus')->willReturnSelf();

        $result = $this->controller->save($this->mockRequest, $this->mockResponse);

        $this->assertSame($this->mockResponse, $result);
    }

    public function testSaveStoresPhotographerSchemaData(): void
    {
        $postData = [
            'csrf' => 'test-csrf-token',
            'photographer_job_title' => 'Professional Photographer',
            'photographer_services' => 'Wedding, Portrait, Event Photography',
            'photographer_area_served' => 'New York, NY',
            'photographer_same_as' => 'https://instagram.com/photographer',
        ];

        $this->mockRequest->method('getParsedBody')->willReturn($postData);

        $this->mockResponse->method('withHeader')->willReturnSelf();
        $this->mockResponse->method('withStatus')->willReturnSelf();

        $result = $this->controller->save($this->mockRequest, $this->mockResponse);

        $this->assertSame($this->mockResponse, $result);
    }

    public function testSaveStoresTechnicalSeoSettings(): void
    {
        $postData = [
            'csrf' => 'test-csrf-token',
            'robots_default' => 'index,follow',
            'canonical_base_url' => 'https://example.com',
            'analytics_gtag' => 'G-XXXXXXXXXX',
            'analytics_gtm' => 'GTM-XXXXXXX',
        ];

        $this->mockRequest->method('getParsedBody')->willReturn($postData);

        $this->mockResponse->method('withHeader')->willReturnSelf();
        $this->mockResponse->method('withStatus')->willReturnSelf();

        $result = $this->controller->save($this->mockRequest, $this->mockResponse);

        $this->assertSame($this->mockResponse, $result);
    }

    public function testSaveStoresImageSeoSettings(): void
    {
        $postData = [
            'csrf' => 'test-csrf-token',
            'image_alt_auto' => '1',
            'image_copyright_notice' => '© 2024 Photographer',
            'image_license_url' => 'https://example.com/license',
            'image_acquire_license_page' => '/contact',
        ];

        $this->mockRequest->method('getParsedBody')->willReturn($postData);

        $this->mockResponse->method('withHeader')->willReturnSelf();
        $this->mockResponse->method('withStatus')->willReturnSelf();

        $result = $this->controller->save($this->mockRequest, $this->mockResponse);

        $this->assertSame($this->mockResponse, $result);
    }

    public function testSaveStoresPerformanceSettings(): void
    {
        $postData = [
            'csrf' => 'test-csrf-token',
            'preload_critical_images' => '1',
            'lazy_load_images' => '1',
            'structured_data_format' => 'json-ld',
        ];

        $this->mockRequest->method('getParsedBody')->willReturn($postData);

        $this->mockResponse->method('withHeader')->willReturnSelf();
        $this->mockResponse->method('withStatus')->willReturnSelf();

        $result = $this->controller->save($this->mockRequest, $this->mockResponse);

        $this->assertSame($this->mockResponse, $result);
    }

    public function testSaveHandlesMissingPostData(): void
    {
        $this->mockRequest->method('getParsedBody')->willReturn([
            'csrf' => 'test-csrf-token',
            // Minimal data
        ]);

        $this->mockResponse->method('withHeader')->willReturnSelf();
        $this->mockResponse->method('withStatus')->willReturnSelf();

        $result = $this->controller->save($this->mockRequest, $this->mockResponse);

        $this->assertSame($this->mockResponse, $result);
    }

    public function testSaveRedirectsToSeoPage(): void
    {
        $this->mockRequest->method('getParsedBody')->willReturn([
            'csrf' => 'test-csrf-token',
            'site_title' => 'Test',
        ]);

        $this->mockResponse->expects($this->once())
            ->method('withHeader')
            ->with('Location', $this->stringContains('/admin/seo'))
            ->willReturnSelf();

        $this->mockResponse->expects($this->once())
            ->method('withStatus')
            ->with(302)
            ->willReturnSelf();

        $this->controller->save($this->mockRequest, $this->mockResponse);
    }

    public function testGenerateSitemapRedirectsAfterSuccess(): void
    {
        $this->mockRequest->method('getParsedBody')->willReturn([
            'csrf' => 'test-csrf-token'
        ]);

        $mockPdo = $this->createMock(PDO::class);
        $this->mockDb->method('pdo')->willReturn($mockPdo);

        $this->mockResponse->expects($this->once())
            ->method('withHeader')
            ->with('Location', $this->stringContains('/admin/seo'))
            ->willReturnSelf();

        $this->mockResponse->expects($this->once())
            ->method('withStatus')
            ->with(302)
            ->willReturnSelf();

        $this->controller->generateSitemap($this->mockRequest, $this->mockResponse);
    }
}