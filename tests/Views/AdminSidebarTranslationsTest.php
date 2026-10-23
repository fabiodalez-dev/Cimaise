<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The admin sidebar must follow the selected admin language.
 *
 * Regression: the `.nav-sub` subtitles were hard-coded in Italian, so an
 * English admin still read "Panoramica generale". Every sidebar string has to
 * go through trans(), and every key it uses has to exist in each admin
 * language pack — a pack that misses one falls back to the raw key.
 */
final class AdminSidebarTranslationsTest extends TestCase
{
    private string $layout;

    /** Sidebar entries printed by the bundled plugins (admin_sidebar_* hooks). */
    private const PLUGIN_SOURCES = [
        '/plugins/cimaise-analytics-pro/plugin.php',
        '/plugins/custom-templates-pro/plugin.php',
    ];

    protected function setUp(): void
    {
        $this->layout = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Views/admin/_layout.twig')
            . (string) file_get_contents(dirname(__DIR__, 2) . '/app/Views/admin/_navigation.twig');
    }

    /** @return array<string, string> source path => contents */
    private function pluginSources(): array
    {
        $sources = [];
        foreach (self::PLUGIN_SOURCES as $relative) {
            $sources[$relative] = (string) file_get_contents(dirname(__DIR__, 2) . $relative);
        }
        return $sources;
    }

    public function testBundledPluginSubtitlesAreTranslated(): void
    {
        foreach ($this->pluginSources() as $path => $source) {
            preg_match_all('/<span class="nav-sub">(.*?)<\/span>/s', $source, $m);
            $this->assertNotEmpty($m[1], 'no sidebar subtitle found in ' . $path);
            foreach ($m[1] as $subtitle) {
                $this->assertMatchesRegularExpression('/^\{\$[a-zA-Z]+\}$/', trim($subtitle), 'hard-coded sidebar subtitle in ' . $path);
            }
            $this->assertMatchesRegularExpression('/trans\(\'admin\.sidebar\.[a-z_]+\'/', $source, $path . ' must translate its sidebar subtitle');
        }
    }

    public function testEverySidebarSubtitleIsTranslated(): void
    {
        preg_match_all('/<span class="nav-sub">(.*?)<\/span>/s', $this->layout, $m);

        $this->assertNotEmpty($m[1], 'no sidebar subtitles found in the admin layout');
        foreach ($m[1] as $subtitle) {
            $this->assertMatchesRegularExpression(
                '/^\{\{\s*trans\(\'admin\.sidebar\.[a-z_]+\'\)\s*\}\}$/',
                trim($subtitle),
                'hard-coded sidebar subtitle: ' . $subtitle
            );
        }
    }

    public function testEverySidebarKeyExistsInEachAdminLanguagePack(): void
    {
        // Keys used by the layout AND by the bundled plugins: a pack missing a
        // plugin key would silently fall back to the English default.
        preg_match_all('/trans\(\'(admin\.sidebar\.[a-z_]+)\'/', $this->layout . implode("\n", $this->pluginSources()), $m);
        $keys = array_values(array_unique($m[1]));
        $this->assertContains('admin.sidebar.analytics_pro_sub', $keys);
        $this->assertContains('admin.sidebar.custom_templates_sub', $keys);
        $this->assertNotEmpty($keys);

        $packs = glob(dirname(__DIR__, 2) . '/storage/translations/*_admin.json') ?: [];
        $this->assertNotEmpty($packs, 'no admin language packs found');

        foreach ($packs as $pack) {
            $data = json_decode((string) file_get_contents($pack), true);
            $this->assertIsArray($data, basename($pack) . ' is not valid JSON');

            $flat = [];
            array_walk_recursive($data, static function ($value, $key) use (&$flat): void {
                $flat[(string) $key] = $value;
            });

            foreach ($keys as $key) {
                $this->assertArrayHasKey($key, $flat, basename($pack) . ' is missing ' . $key);
                $this->assertNotSame('', trim((string) $flat[$key]), basename($pack) . ' has an empty ' . $key);
            }
        }
    }
}
