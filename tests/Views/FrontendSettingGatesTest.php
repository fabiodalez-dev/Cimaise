<?php

declare(strict_types=1);

use App\Services\TwigGlobalsCache;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * Frontend partials must honour an explicit FALSE coming from the settings.
 *
 * Regression: Twig's `|default(true)` also replaces FALSE (the filter tests
 * `empty`, not `defined`), so a toggle switched OFF in the admin was silently
 * treated as ON — right-click blocker, cookie-banner consent gate, image
 * JSON-LD. These render the real templates with the globals a disabled
 * setting produces and assert the markup is gone.
 */
final class FrontendSettingGatesTest extends TestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        $loader = new FilesystemLoader(dirname(__DIR__, 2) . '/app/Views');
        $this->twig = new Environment($loader, ['cache' => false, 'strict_variables' => false]);
        $this->twig->addFunction(new TwigFunction('csp_nonce', static fn (): string => 'test-nonce'));
        $this->twig->addFunction(new TwigFunction('trans', static fn (string $key, array $p = [], ?string $default = null): string => $default ?? $key));
        $this->twig->addGlobal('base_path', '');
        $this->twig->addGlobal('site_title', 'Site');
    }

    private function schema(bool $enabled, bool $breadcrumbs = true): array
    {
        $s = TwigGlobalsCache::getDefaults('')['schema'];
        $s['enabled'] = $enabled;
        $s['schema_enabled'] = $enabled;
        $s['breadcrumbs_enabled'] = $enabled && $breadcrumbs;
        return $s;
    }

    private function image(): array
    {
        return ['id' => 1, 'fallback_src' => '/media/1_md.jpg', 'width' => 800, 'height' => 600, 'caption' => 'A photo'];
    }

    public function testImageJsonLdIsSuppressedWhenSchemaIsDisabled(): void
    {
        $ctx = ['images' => [$this->image()], 'album' => ['title' => 'Album'], 'canonical_base' => 'https://x.test', 'canonical_url' => 'https://x.test/album/a'];

        $on = $this->twig->render('frontend/_image_schema.twig', $ctx + ['schema' => $this->schema(true)]);
        self::assertStringContainsString('"@type":"ImageGallery"', $on);

        $off = $this->twig->render('frontend/_image_schema.twig', $ctx + ['schema' => $this->schema(false)]);
        self::assertStringNotContainsString('ld+json', $off);
    }

    public function testBreadcrumbJsonLdHonoursBreadcrumbsToggle(): void
    {
        $ctx = ['album' => ['title' => 'Album', 'category' => ['name' => 'Cat', 'slug' => 'cat']], 'canonical_url' => 'https://x.test/album/a', 'canonical_base' => 'https://x.test'];

        $on = $this->twig->render('frontend/_breadcrumbs.twig', $ctx + ['schema' => $this->schema(true)]);
        self::assertStringContainsString('BreadcrumbList', $on);

        $off = $this->twig->render('frontend/_breadcrumbs.twig', $ctx + ['schema' => $this->schema(true, false)]);
        self::assertStringNotContainsString('BreadcrumbList', $off);
        // The visible breadcrumb trail itself must stay.
        self::assertStringContainsString('<nav', $off);
    }

    public function testWebsiteJsonLdIsSuppressedWhenSchemaIsDisabled(): void
    {
        $ctx = ['canonical_base' => 'https://x.test', 'canonical_url' => 'https://x.test/', 'meta_description' => 'd'];
        self::assertStringContainsString('"@type": "WebSite"', $this->twig->render('frontend/home/_website_jsonld.twig', $ctx + ['schema' => $this->schema(true)]));
        self::assertSame('', trim($this->twig->render('frontend/home/_website_jsonld.twig', $ctx + ['schema' => $this->schema(false)])));
    }

    public function testAnalyticsTagsDoNotWaitForConsentWhenBannerIsDisabled(): void
    {
        $ctx = ['analytics_gtag' => 'G-TEST'];
        $banner = $this->twig->render('frontend/_analytics_tags.twig', $ctx + ['cookie_banner_enabled' => true]);
        self::assertStringContainsString('var consentRequired = true;', $banner);

        // Banner OFF: the tags must load at once (nothing could ever grant consent).
        $noBanner = $this->twig->render('frontend/_analytics_tags.twig', $ctx + ['cookie_banner_enabled' => false]);
        self::assertStringContainsString('var consentRequired = false;', $noBanner);
    }

    public function testLcpPreloadHonoursPreloadToggle(): void
    {
        $ctx = ['all_images' => [['sources' => ['avif' => ['/media/1_md.avif 800w']], 'fallback_src' => '/media/1_md.jpg']]];
        self::assertStringContainsString('rel="preload"', $this->twig->render('frontend/_lcp_preload.twig', $ctx + ['preload_critical_images' => true]));
        self::assertSame('', trim($this->twig->render('frontend/_lcp_preload.twig', $ctx + ['preload_critical_images' => false])));
    }

    public function testSmartAltHonoursAutoAltToggle(): void
    {
        // Macros only see Twig GLOBALS (never the caller's context) — exactly how
        // the app exposes the setting (TwigGlobalsCache -> addGlobal).
        $src = "{% import 'frontend/_image_macros.twig' as Img %}{{ Img.smart_alt(image, album, site_title) }}";
        $image = ['location_name' => 'Rome'];
        $album = ['title' => 'Album'];

        $this->twig->addGlobal('image_alt_auto', true);
        self::assertSame('Album | Rome', trim($this->twig->createTemplate($src)->render(['image' => $image, 'album' => $album])));

        $this->setUp();
        $this->twig->addGlobal('image_alt_auto', false);
        $off = $this->twig->createTemplate($src);
        self::assertSame('', trim($off->render(['image' => $image, 'album' => $album])));
        // Explicit alt text always wins.
        self::assertSame('Given', trim($off->render(['image' => $image + ['alt_text' => 'Given'], 'album' => $album])));
    }

    public function testSchemaShapeCarriesAreaServedInBothBranches(): void
    {
        $defaults = TwigGlobalsCache::getDefaults('');
        self::assertArrayHasKey('photographer_area_served', $defaults['schema']);
        foreach (['site_keywords', 'lazy_load_images', 'preload_critical_images', 'image_alt_auto'] as $key) {
            self::assertArrayHasKey($key, $defaults, "global {$key} must exist so templates never read an undefined value");
        }
    }
}
