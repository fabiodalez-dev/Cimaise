<?php

declare(strict_types=1);

use App\Middlewares\CompressionMiddleware;
use PHPUnit\Framework\TestCase;

final class CompressionDetectionTest extends TestCase
{
    public function testExplicitDisableEnvMeansHandledElsewhere(): void
    {
        self::assertTrue(CompressionMiddleware::detectServerCompression([], '1', null));
    }

    public function testApacheFlagFromHtaccessIsHonouredUnderFpm(): void
    {
        self::assertTrue(CompressionMiddleware::detectServerCompression(['CIMAISE_SERVER_COMPRESSION' => '1'], null, null));
    }

    public function testFlagRenamedByRewriteIsHonoured(): void
    {
        self::assertTrue(CompressionMiddleware::detectServerCompression(['REDIRECT_CIMAISE_SERVER_COMPRESSION' => '1'], null, null));
    }

    public function testModPhpModuleListIsHonoured(): void
    {
        self::assertTrue(CompressionMiddleware::detectServerCompression([], null, ['core', 'mod_deflate']));
        self::assertFalse(CompressionMiddleware::detectServerCompression([], null, ['core', 'mod_rewrite']));
    }

    public function testWithoutAnySignalPhpKeepsCompressing(): void
    {
        self::assertFalse(CompressionMiddleware::detectServerCompression([], null, null));
    }

    public function testFalseyValuesDoNotDisablePhpCompression(): void
    {
        self::assertFalse(CompressionMiddleware::detectServerCompression(['CIMAISE_SERVER_COMPRESSION' => '0'], 'false', null));
    }

    public function testHtaccessOnlySetsTheFlagWhereTheModuleIsLoaded(): void
    {
        $htaccess = (string) file_get_contents(dirname(__DIR__, 2) . '/public/.htaccess');
        foreach (['mod_brotli.c', 'mod_deflate.c'] as $module) {
            $start = strpos($htaccess, '<IfModule ' . $module . '>');
            self::assertNotFalse($start, $module);
            $block = substr($htaccess, $start, strpos($htaccess, "\n</IfModule>", $start) - $start);
            self::assertStringContainsString('SetEnv CIMAISE_SERVER_COMPRESSION 1', $block, $module);
        }
    }
}
