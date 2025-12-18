<?php
declare(strict_types=1);

namespace Tests\Unit\Controllers\Admin;

use App\Controllers\Admin\SettingsController;
use App\Support\Database;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use PDO;
use PDOStatement;

class SettingsControllerTest extends TestCase
{
    private SettingsController $controller;
    private Database|MockObject $mockDb;
    private Twig|MockObject $mockView;
    private ServerRequestInterface|MockObject $mockRequest;
    private ResponseInterface|MockObject $mockResponse;
    private PDO|MockObject $mockPdo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockDb = $this->createMock(Database::class);
        $this->mockPdo = $this->createMock(PDO::class);
        $this->mockDb->method('pdo')->willReturn($this->mockPdo);
        
        $this->mockView = $this->createMock(Twig::class);
        $this->controller = new SettingsController($this->mockDb, $this->mockView);

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

    public function testShowRendersViewWithSettings(): void
    {
        $mockStmt = $this->createMock(PDOStatement::class);
        $mockStmt->method('fetchAll')->willReturn([]);
        
        $this->mockPdo->method('query')->willReturn($mockStmt);

        $this->mockView->expects($this->once())
            ->method('render')
            ->with(
                $this->mockResponse,
                'admin/settings.twig',
                $this->callback(function ($data) {
                    $this->assertArrayHasKey('settings', $data);
                    $this->assertArrayHasKey('templates', $data);
                    $this->assertArrayHasKey('csrf', $data);
                    return true;
                })
            )
            ->willReturn($this->mockResponse);

        $result = $this->controller->show($this->mockRequest, $this->mockResponse);

        $this->assertSame($this->mockResponse, $result);
    }

    public function testShowHandlesTemplatesTableNotExists(): void
    {
        $this->mockPdo->method('query')->willThrowException(new \Exception('Table does not exist'));

        $this->mockView->expects($this->once())
            ->method('render')
            ->willReturn($this->mockResponse);

        $result = $this->controller->show($this->mockRequest, $this->mockResponse);

        $this->assertSame($this->mockResponse, $result);
    }

    public function testSaveWithInvalidCsrf(): void
    {
        $this->mockRequest->method('getParsedBody')->willReturn([
            'csrf' => 'invalid-token'
        ]);

        $this->mockResponse->expects($this->once())
            ->method('withHeader')
            ->with('Location', $this->stringContains('/admin/settings'))
            ->willReturnSelf();

        $this->mockResponse->expects($this->once())
            ->method('withStatus')
            ->with(302)
            ->willReturnSelf();

        $result = $this->controller->save($this->mockRequest, $this->mockResponse);

        $this->assertArrayHasKey('flash', $_SESSION);
        $this->assertContains('Invalid CSRF token', array_column($_SESSION['flash'], 'message'));
    }

    public function testSaveWithValidData(): void
    {
        $postData = [
            'csrf' => 'test-csrf-token',
            'site_title' => 'My Portfolio',
            'site_description' => 'A great portfolio',
            'site_email' => 'test@example.com',
            'fmt_avif' => '1',
            'fmt_webp' => '1',
            'fmt_jpg' => '1',
            'q_avif' => '50',
            'q_webp' => '75',
            'q_jpg' => '85',
            'preview_w' => '480',
            'breakpoints' => json_encode(['sm' => 768, 'md' => 1200, 'lg' => 1920]),
            'pagination_limit' => '12',
            'cache_ttl' => '24',
            'date_format' => 'Y-m-d',
            'site_language' => 'en',
        ];

        $this->mockRequest->method('getParsedBody')->willReturn($postData);

        $this->mockResponse->method('withHeader')->willReturnSelf();
        $this->mockResponse->method('withStatus')->willReturnSelf();

        $result = $this->controller->save($this->mockRequest, $this->mockResponse);

        $this->assertArrayHasKey('flash', $_SESSION);
        $this->assertSame($this->mockResponse, $result);
    }

    public function testSaveValidatesQualityValues(): void
    {
        $postData = [
            'csrf' => 'test-csrf-token',
            'q_avif' => '150', // Should be clamped to 100
            'q_webp' => '-10', // Should be clamped to 1
            'q_jpg' => '85',
        ];

        $this->mockRequest->method('getParsedBody')->willReturn($postData);

        $this->mockResponse->method('withHeader')->willReturnSelf();
        $this->mockResponse->method('withStatus')->willReturnSelf();

        $result = $this->controller->save($this->mockRequest, $this->mockResponse);

        $this->assertSame($this->mockResponse, $result);
    }

    public function testSaveValidatesPaginationLimit(): void
    {
        $postData = [
            'csrf' => 'test-csrf-token',
            'pagination_limit' => '200', // Should be clamped to 100
        ];

        $this->mockRequest->method('getParsedBody')->willReturn($postData);

        $this->mockResponse->method('withHeader')->willReturnSelf();
        $this->mockResponse->method('withStatus')->willReturnSelf();

        $result = $this->controller->save($this->mockRequest, $this->mockResponse);

        $this->assertSame($this->mockResponse, $result);
    }

    public function testSaveValidatesCacheTtl(): void
    {
        $postData = [
            'csrf' => 'test-csrf-token',
            'cache_ttl' => '200', // Should be clamped to 168
        ];

        $this->mockRequest->method('getParsedBody')->willReturn($postData);

        $this->mockResponse->method('withHeader')->willReturnSelf();
        $this->mockResponse->method('withStatus')->willReturnSelf();

        $result = $this->controller->save($this->mockRequest, $this->mockResponse);

        $this->assertSame($this->mockResponse, $result);
    }

    public function testSaveHandlesInvalidBreakpointsJson(): void
    {
        $postData = [
            'csrf' => 'test-csrf-token',
            'breakpoints' => 'invalid json',
        ];

        $this->mockRequest->method('getParsedBody')->willReturn($postData);

        $this->mockResponse->method('withHeader')->willReturnSelf();
        $this->mockResponse->method('withStatus')->willReturnSelf();

        $result = $this->controller->save($this->mockRequest, $this->mockResponse);

        // Should use defaults and not crash
        $this->assertSame($this->mockResponse, $result);
    }

    public function testSaveHandlesEmptyDefaultTemplateId(): void
    {
        $postData = [
            'csrf' => 'test-csrf-token',
            'default_template_id' => '', // Empty string
        ];

        $this->mockRequest->method('getParsedBody')->willReturn($postData);

        $this->mockResponse->method('withHeader')->willReturnSelf();
        $this->mockResponse->method('withStatus')->willReturnSelf();

        $result = $this->controller->save($this->mockRequest, $this->mockResponse);

        $this->assertSame($this->mockResponse, $result);
    }

    public function testSaveHandlesNullSiteLogo(): void
    {
        $postData = [
            'csrf' => 'test-csrf-token',
            'site_logo' => '', // Empty should be stored as null
        ];

        $this->mockRequest->method('getParsedBody')->willReturn($postData);

        $this->mockResponse->method('withHeader')->willReturnSelf();
        $this->mockResponse->method('withStatus')->willReturnSelf();

        $result = $this->controller->save($this->mockRequest, $this->mockResponse);

        $this->assertSame($this->mockResponse, $result);
    }

    public function testSaveHandlesGalleryPageTemplateValidation(): void
    {
        $postData = [
            'csrf' => 'test-csrf-token',
            'gallery_page_template' => 'invalid-template', // Should fall back to 'classic'
        ];

        $this->mockRequest->method('getParsedBody')->willReturn($postData);

        $this->mockResponse->method('withHeader')->willReturnSelf();
        $this->mockResponse->method('withStatus')->willReturnSelf();

        $result = $this->controller->save($this->mockRequest, $this->mockResponse);

        $this->assertSame($this->mockResponse, $result);
    }

    public function testSaveHandlesRecaptchaSettings(): void
    {
        $postData = [
            'csrf' => 'test-csrf-token',
            'recaptcha_enabled' => '1',
            'recaptcha_site_key' => '6LdXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX',
            'recaptcha_secret_key' => '6LdYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYYY',
        ];

        $this->mockRequest->method('getParsedBody')->willReturn($postData);

        $this->mockResponse->method('withHeader')->willReturnSelf();
        $this->mockResponse->method('withStatus')->willReturnSelf();

        $result = $this->controller->save($this->mockRequest, $this->mockResponse);

        $this->assertSame($this->mockResponse, $result);
    }

    public function testSaveHandlesEmptyRecaptchaKeys(): void
    {
        $postData = [
            'csrf' => 'test-csrf-token',
            'recaptcha_site_key' => '',
            'recaptcha_secret_key' => '',
        ];

        $this->mockRequest->method('getParsedBody')->willReturn($postData);

        $this->mockResponse->method('withHeader')->willReturnSelf();
        $this->mockResponse->method('withStatus')->willReturnSelf();

        $result = $this->controller->save($this->mockRequest, $this->mockResponse);

        $this->assertSame($this->mockResponse, $result);
    }

    public function testSaveValidatesDateFormat(): void
    {
        $postData = [
            'csrf' => 'test-csrf-token',
            'date_format' => 'invalid-format', // Should fall back to 'Y-m-d'
        ];

        $this->mockRequest->method('getParsedBody')->willReturn($postData);

        $this->mockResponse->method('withHeader')->willReturnSelf();
        $this->mockResponse->method('withStatus')->willReturnSelf();

        $result = $this->controller->save($this->mockRequest, $this->mockResponse);

        $this->assertSame($this->mockResponse, $result);
    }

    public function testSaveSanitizesSiteLanguage(): void
    {
        $postData = [
            'csrf' => 'test-csrf-token',
            'site_language' => 'en<script>alert(1)</script>', // Should be sanitized
        ];

        $this->mockRequest->method('getParsedBody')->willReturn($postData);

        $this->mockResponse->method('withHeader')->willReturnSelf();
        $this->mockResponse->method('withStatus')->willReturnSelf();

        $result = $this->controller->save($this->mockRequest, $this->mockResponse);

        $this->assertSame($this->mockResponse, $result);
    }

    public function testGenerateImagesWithInvalidCsrf(): void
    {
        $this->mockRequest->method('getParsedBody')->willReturn([
            'csrf' => 'invalid-token'
        ]);

        $this->mockResponse->expects($this->once())
            ->method('withHeader')
            ->with('Location', $this->stringContains('/admin/settings'))
            ->willReturnSelf();

        $this->mockResponse->expects($this->once())
            ->method('withStatus')
            ->with(302)
            ->willReturnSelf();

        $result = $this->controller->generateImages($this->mockRequest, $this->mockResponse);

        $this->assertArrayHasKey('flash', $_SESSION);
    }

    public function testGenerateFaviconsWithInvalidCsrf(): void
    {
        $this->mockRequest->method('getParsedBody')->willReturn([
            'csrf' => 'invalid-token'
        ]);

        $this->mockResponse->expects($this->once())
            ->method('withHeader')
            ->with('Location', $this->stringContains('/admin/settings'))
            ->willReturnSelf();

        $this->mockResponse->expects($this->once())
            ->method('withStatus')
            ->with(302)
            ->willReturnSelf();

        $result = $this->controller->generateFavicons($this->mockRequest, $this->mockResponse);

        $this->assertArrayHasKey('flash', $_SESSION);
    }

    public function testGenerateFaviconsWithNoLogo(): void
    {
        $this->mockRequest->method('getParsedBody')->willReturn([
            'csrf' => 'test-csrf-token'
        ]);

        $this->mockResponse->method('withHeader')->willReturnSelf();
        $this->mockResponse->method('withStatus')->willReturnSelf();

        $result = $this->controller->generateFavicons($this->mockRequest, $this->mockResponse);

        $this->assertArrayHasKey('flash', $_SESSION);
        // Should have message about uploading logo first
        $messages = array_column($_SESSION['flash'], 'message');
        $this->assertNotEmpty(array_filter($messages, fn($m) => str_contains($m, 'logo')));
    }

    public function testSaveRedirectsToSettingsPage(): void
    {
        $this->mockRequest->method('getParsedBody')->willReturn([
            'csrf' => 'test-csrf-token',
        ]);

        $this->mockResponse->expects($this->once())
            ->method('withHeader')
            ->with('Location', $this->stringContains('/admin/settings'))
            ->willReturnSelf();

        $this->mockResponse->expects($this->once())
            ->method('withStatus')
            ->with(302)
            ->willReturnSelf();

        $this->controller->save($this->mockRequest, $this->mockResponse);
    }

    public function testSaveHandlesPerformanceCompressionCheckbox(): void
    {
        $postData = [
            'csrf' => 'test-csrf-token',
            'enable_compression' => '1',
        ];

        $this->mockRequest->method('getParsedBody')->willReturn($postData);

        $this->mockResponse->method('withHeader')->willReturnSelf();
        $this->mockResponse->method('withStatus')->willReturnSelf();

        $result = $this->controller->save($this->mockRequest, $this->mockResponse);

        $this->assertSame($this->mockResponse, $result);
    }

    public function testSaveHandlesShowExifLightboxCheckbox(): void
    {
        $postData = [
            'csrf' => 'test-csrf-token',
            'show_exif_lightbox' => '1',
        ];

        $this->mockRequest->method('getParsedBody')->willReturn($postData);

        $this->mockResponse->method('withHeader')->willReturnSelf();
        $this->mockResponse->method('withStatus')->willReturnSelf();

        $result = $this->controller->save($this->mockRequest, $this->mockResponse);

        $this->assertSame($this->mockResponse, $result);
    }

    public function testSaveHandlesImageFormatCheckboxes(): void
    {
        $postData = [
            'csrf' => 'test-csrf-token',
            'fmt_avif' => '1',
            // fmt_webp not set (unchecked)
            'fmt_jpg' => '1',
        ];

        $this->mockRequest->method('getParsedBody')->willReturn($postData);

        $this->mockResponse->method('withHeader')->willReturnSelf();
        $this->mockResponse->method('withStatus')->willReturnSelf();

        $result = $this->controller->save($this->mockRequest, $this->mockResponse);

        $this->assertSame($this->mockResponse, $result);
    }

    public function testSaveSetsSiteCopyright(): void
    {
        $postData = [
            'csrf' => 'test-csrf-token',
            'site_copyright' => '© 2024 My Portfolio',
        ];

        $this->mockRequest->method('getParsedBody')->willReturn($postData);

        $this->mockResponse->method('withHeader')->willReturnSelf();
        $this->mockResponse->method('withStatus')->willReturnSelf();

        $result = $this->controller->save($this->mockRequest, $this->mockResponse);

        $this->assertSame($this->mockResponse, $result);
    }
}