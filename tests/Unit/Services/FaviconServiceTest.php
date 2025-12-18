<?php
declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\FaviconService;
use PHPUnit\Framework\TestCase;

class FaviconServiceTest extends TestCase
{
    private string $testPublicPath;
    private FaviconService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testPublicPath = sys_get_temp_dir() . '/favicon_test_' . uniqid();
        mkdir($this->testPublicPath, 0755, true);
        $this->service = new FaviconService($this->testPublicPath);
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

    /**
     * Create a test image for testing
     */
    private function createTestImage(int $width = 512, int $height = 512, string $format = 'png'): string
    {
        $image = imagecreatetruecolor($width, $height);
        
        // Enable alpha channel
        imagealphablending($image, false);
        imagesavealpha($image, true);
        
        // Fill with semi-transparent blue
        $color = imagecolorallocatealpha($image, 0, 0, 255, 64);
        imagefill($image, 0, 0, $color);
        
        // Draw a circle in the center
        $white = imagecolorallocate($image, 255, 255, 255);
        imagefilledellipse($image, $width / 2, $height / 2, $width / 2, $height / 2, $white);
        
        $path = $this->testPublicPath . '/test_source.' . $format;
        
        switch ($format) {
            case 'png':
                imagepng($image, $path);
                break;
            case 'jpg':
            case 'jpeg':
                imagejpeg($image, $path);
                break;
            case 'webp':
                imagewebp($image, $path);
                break;
            case 'gif':
                imagegif($image, $path);
                break;
        }
        
        imagedestroy($image);
        return $path;
    }

    public function testConstructorTrimsTrailingSlash(): void
    {
        $service = new FaviconService($this->testPublicPath . '/');
        $this->assertInstanceOf(FaviconService::class, $service);
    }

    public function testGenerateFaviconsWithNonExistentFile(): void
    {
        $result = $this->service->generateFavicons('/nonexistent/path/image.png');
        
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('not found or not readable', $result['error']);
    }

    public function testGenerateFaviconsWithInvalidImageFile(): void
    {
        // Create a non-image file
        $invalidFile = $this->testPublicPath . '/invalid.txt';
        file_put_contents($invalidFile, 'This is not an image');
        
        $result = $this->service->generateFavicons($invalidFile);
        
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Invalid image file', $result['error']);
        
        unlink($invalidFile);
    }

    public function testGenerateFaviconsWithValidPngImage(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD extension not available');
        }

        $sourcePath = $this->createTestImage(512, 512, 'png');
        $result = $this->service->generateFavicons($sourcePath);
        
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('generated', $result);
        $this->assertArrayHasKey('errors', $result);
        
        // Check that expected files were generated
        $expectedFiles = [
            'favicon.ico',
            'favicon-16x16.png',
            'favicon-32x32.png',
            'favicon-96x96.png',
            'apple-touch-icon.png',
            'android-chrome-192x192.png',
            'android-chrome-512x512.png',
            'site.webmanifest'
        ];
        
        foreach ($expectedFiles as $file) {
            $this->assertContains($file, $result['generated'], "Expected file $file was not generated");
        }
        
        // Verify files exist
        $this->assertFileExists($this->testPublicPath . '/favicon.ico');
        $this->assertFileExists($this->testPublicPath . '/favicon-16x16.png');
        $this->assertFileExists($this->testPublicPath . '/apple-touch-icon.png');
        $this->assertFileExists($this->testPublicPath . '/site.webmanifest');
        
        unlink($sourcePath);
    }

    public function testGenerateFaviconsWithJpegImage(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD extension not available');
        }

        $sourcePath = $this->createTestImage(512, 512, 'jpg');
        $result = $this->service->generateFavicons($sourcePath);
        
        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['generated']);
        
        unlink($sourcePath);
    }

    public function testGenerateFaviconsWithWebpImage(): void
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagewebp')) {
            $this->markTestSkipped('GD extension or WebP support not available');
        }

        $sourcePath = $this->createTestImage(512, 512, 'webp');
        $result = $this->service->generateFavicons($sourcePath);
        
        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['generated']);
        
        unlink($sourcePath);
    }

    public function testGeneratedFaviconSizes(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD extension not available');
        }

        $sourcePath = $this->createTestImage(512, 512, 'png');
        $result = $this->service->generateFavicons($sourcePath);
        
        $this->assertTrue($result['success']);
        
        // Check dimensions of generated PNGs
        $sizesToCheck = [
            'favicon-16x16.png' => 16,
            'favicon-32x32.png' => 32,
            'favicon-96x96.png' => 96,
            'apple-touch-icon.png' => 180,
            'android-chrome-192x192.png' => 192,
            'android-chrome-512x512.png' => 512,
        ];
        
        foreach ($sizesToCheck as $filename => $expectedSize) {
            $filepath = $this->testPublicPath . '/' . $filename;
            if (file_exists($filepath)) {
                $imageInfo = getimagesize($filepath);
                $this->assertEquals($expectedSize, $imageInfo[0], "Width mismatch for $filename");
                $this->assertEquals($expectedSize, $imageInfo[1], "Height mismatch for $filename");
            }
        }
        
        unlink($sourcePath);
    }

    public function testGeneratedWebManifestContent(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD extension not available');
        }

        $sourcePath = $this->createTestImage(512, 512, 'png');
        $result = $this->service->generateFavicons($sourcePath);
        
        $this->assertTrue($result['success']);
        
        $manifestPath = $this->testPublicPath . '/site.webmanifest';
        $this->assertFileExists($manifestPath);
        
        $manifestContent = file_get_contents($manifestPath);
        $manifest = json_decode($manifestContent, true);
        
        $this->assertIsArray($manifest);
        $this->assertArrayHasKey('name', $manifest);
        $this->assertArrayHasKey('short_name', $manifest);
        $this->assertArrayHasKey('icons', $manifest);
        $this->assertArrayHasKey('theme_color', $manifest);
        $this->assertArrayHasKey('background_color', $manifest);
        $this->assertArrayHasKey('display', $manifest);
        
        $this->assertCount(2, $manifest['icons']);
        $this->assertEquals('/android-chrome-192x192.png', $manifest['icons'][0]['src']);
        $this->assertEquals('192x192', $manifest['icons'][0]['sizes']);
        $this->assertEquals('image/png', $manifest['icons'][0]['type']);
        
        unlink($sourcePath);
    }

    public function testCleanupFavicons(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD extension not available');
        }

        // Generate favicons first
        $sourcePath = $this->createTestImage(512, 512, 'png');
        $result = $this->service->generateFavicons($sourcePath);
        $this->assertTrue($result['success']);
        
        // Verify files exist
        $this->assertFileExists($this->testPublicPath . '/favicon.ico');
        $this->assertFileExists($this->testPublicPath . '/site.webmanifest');
        
        // Clean up
        $cleanupResult = $this->service->cleanupFavicons();
        $this->assertTrue($cleanupResult);
        
        // Verify files are deleted
        $this->assertFileDoesNotExist($this->testPublicPath . '/favicon.ico');
        $this->assertFileDoesNotExist($this->testPublicPath . '/favicon-16x16.png');
        $this->assertFileDoesNotExist($this->testPublicPath . '/site.webmanifest');
        
        unlink($sourcePath);
    }

    public function testCleanupFaviconsWhenNoFilesExist(): void
    {
        // Should return true even when no files exist
        $result = $this->service->cleanupFavicons();
        $this->assertTrue($result);
    }

    public function testCleanupFaviconsPartialFiles(): void
    {
        // Create only some favicon files
        file_put_contents($this->testPublicPath . '/favicon.ico', 'fake favicon');
        file_put_contents($this->testPublicPath . '/site.webmanifest', '{}');
        
        $result = $this->service->cleanupFavicons();
        $this->assertTrue($result);
        
        $this->assertFileDoesNotExist($this->testPublicPath . '/favicon.ico');
        $this->assertFileDoesNotExist($this->testPublicPath . '/site.webmanifest');
    }

    public function testGenerateFaviconsPreservesTransparency(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD extension not available');
        }

        $sourcePath = $this->createTestImage(512, 512, 'png');
        $result = $this->service->generateFavicons($sourcePath);
        
        $this->assertTrue($result['success']);
        
        // Check that PNG files support alpha channel
        $pngPath = $this->testPublicPath . '/favicon-32x32.png';
        if (file_exists($pngPath)) {
            $image = imagecreatefrompng($pngPath);
            $this->assertNotFalse($image);
            
            // Verify alpha channel is enabled
            $this->assertTrue(imagesavealpha($image, true));
            imagedestroy($image);
        }
        
        unlink($sourcePath);
    }

    public function testGenerateFaviconsWithNonSquareImage(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD extension not available');
        }

        // Create a rectangular image
        $image = imagecreatetruecolor(800, 400);
        $white = imagecolorallocate($image, 255, 255, 255);
        imagefill($image, 0, 0, $white);
        
        $sourcePath = $this->testPublicPath . '/rect_source.png';
        imagepng($image, $sourcePath);
        imagedestroy($image);
        
        $result = $this->service->generateFavicons($sourcePath);
        
        // Should still succeed and resize properly
        $this->assertTrue($result['success']);
        
        // Verify generated images are square
        $pngPath = $this->testPublicPath . '/favicon-32x32.png';
        if (file_exists($pngPath)) {
            $imageInfo = getimagesize($pngPath);
            $this->assertEquals(32, $imageInfo[0]);
            $this->assertEquals(32, $imageInfo[1]);
        }
        
        unlink($sourcePath);
    }

    public function testGenerateFaviconsErrorsArray(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD extension not available');
        }

        $sourcePath = $this->createTestImage(512, 512, 'png');
        $result = $this->service->generateFavicons($sourcePath);
        
        $this->assertArrayHasKey('errors', $result);
        $this->assertIsArray($result['errors']);
        
        unlink($sourcePath);
    }

    public function testFaviconIcoFileGeneration(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD extension not available');
        }

        $sourcePath = $this->createTestImage(512, 512, 'png');
        $result = $this->service->generateFavicons($sourcePath);
        
        $this->assertTrue($result['success']);
        
        $icoPath = $this->testPublicPath . '/favicon.ico';
        $this->assertFileExists($icoPath);
        
        // Verify it's a valid file (GD saves as PNG with .ico extension)
        $this->assertGreaterThan(0, filesize($icoPath));
        
        unlink($sourcePath);
    }
}