<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FrontendLayoutScriptTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    public function testLayoutHasNoLargeInlineScriptLeft(): void
    {
        $layout = (string) file_get_contents(self::root() . '/app/Views/frontend/_layout.twig');
        preg_match_all('#<script\b(?![^>]*\bsrc=)[^>]*>(.*?)</script>#s', $layout, $matches);
        foreach ($matches[1] as $script) {
            self::assertLessThan(8 * 1024, strlen($script), 'Inline scripts are re-downloaded on every page: move big ones to public/assets/js');
        }
        self::assertMatchesRegularExpression('#<script nonce="\{\{ csp_nonce\(\) \}\}" src="\{\{ base_path \}\}/assets/js/frontend-layout\.js\?v=\{\{ app_version#', $layout);
    }

    public function testExtractedRuntimeIsPlainJavascript(): void
    {
        $js = (string) file_get_contents(self::root() . '/public/assets/js/frontend-layout.js');
        self::assertDoesNotMatchRegularExpression('/\{\{|\{%|\{#/', $js, 'Twig syntax would be served verbatim');
        self::assertStringContainsString('function loadPhotoSwipe', $js);
        self::assertStringContainsString('function fallbackClose', $js);
    }

    public function testEveryTranslatedStringUsedByTheRuntimeIsProvidedByTheLayout(): void
    {
        $js = (string) file_get_contents(self::root() . '/public/assets/js/frontend-layout.js');
        $layout = (string) file_get_contents(self::root() . '/app/Views/frontend/_layout.twig');
        preg_match_all('/__cimaiseLayout\.i18n\.([A-Za-z]+)/', $js, $used);
        self::assertNotEmpty($used[1]);
        foreach (array_unique($used[1]) as $key) {
            self::assertMatchesRegularExpression('/\b' . $key . ': trans\(/', $layout, "i18n.$key is used by frontend-layout.js");
        }
    }

    public function testEarlyHintsNoLongerPreloadUnusedOrUnversionedAssets(): void
    {
        self::assertFileDoesNotExist(self::root() . '/app/Middlewares/EarlyHintsMiddleware.php');
        self::assertStringNotContainsString('EarlyHintsMiddleware', (string) file_get_contents(self::root() . '/public/index.php'));
    }
}
