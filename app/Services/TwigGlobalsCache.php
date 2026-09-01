<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\QueryCache;
use App\Support\Logger;

/**
 * Caches Twig global variables to avoid rebuilding them on every request.
 *
 * The index.php bootstrap was loading 40+ settings on every request.
 * This class caches the computed globals in APCu/file cache for 5 minutes.
 *
 * Performance impact: Reduces bootstrap settings queries from ~50 to ~1
 */
class TwigGlobalsCache
{
    /**
     * Versioned prefix: bump the `v<N>` segment whenever the SHAPE of the cached
     * globals changes (new/renamed keys), so deployed caches with the old shape
     * are never served to templates expecting the new key.
     */
    private const CACHE_KEY_PREFIX = 'twig_globals:v4:';
    private const TTL = 300; // 5 minutes

    /**
     * Get cached globals for Twig environment.
     *
     * @param Database $db Database connection
     * @param string $basePath Application base path
     * @param bool $isAdminRoute Whether this is an admin route
     * @return array Associative array of global variables
     */
    public static function getGlobals(
        Database $db,
        string $basePath,
        bool $isAdminRoute
    ): array {
        $cacheKey = self::CACHE_KEY_PREFIX . ($isAdminRoute ? 'admin' : 'frontend');

        return QueryCache::getInstance()->remember(
            $cacheKey,
            fn () => self::buildGlobals($db, $basePath, $isAdminRoute),
            self::TTL
        );
    }

    /**
     * Invalidate all Twig globals cache.
     * Call this when settings are changed.
     */
    public static function invalidate(): void
    {
        $cache = QueryCache::getInstance();
        $cache->forget(self::CACHE_KEY_PREFIX . 'frontend');
        $cache->forget(self::CACHE_KEY_PREFIX . 'admin');
    }

    /**
     * Build all Twig globals from settings.
     * This extracts the logic from index.php lines 316-576.
     */
    private static function buildGlobals(
        Database $db,
        string $basePath,
        bool $isAdminRoute
    ): array {
        try {
            $settings = new SettingsService($db);

            // Core globals (both admin and frontend)
            $siteTitle = (string) ($settings->get('site.title', 'Cimaise') ?? 'Cimaise');

            $globals = [
                // URL slugs
                'about_url' => $basePath . '/' . self::getSlug($settings, 'about.slug', 'about'),
                'galleries_url' => $basePath . '/' . self::getSlug($settings, 'galleries.slug', 'galleries'),
                'license_url' => $basePath . '/' . self::getSlug($settings, 'license.slug', 'license'),
                'privacy_url' => $basePath . '/' . self::getSlug($settings, 'privacy.slug', 'privacy-policy'),
                'cookie_url' => $basePath . '/' . self::getSlug($settings, 'cookie.slug', 'cookie-policy'),

                // Site identity
                'site_title' => $siteTitle,
                'site_logo' => $settings->get('site.logo'),
                'logo_type' => (string) ($settings->get('site.logo_type', 'text') ?? 'text'),
                'site_copyright' => (string) ($settings->get('site.copyright', '') ?? ''),

                // Footer link visibility
                'license_show_in_footer' => (bool) $settings->get('license.show_in_footer', false),
                'license_title_footer' => (string) ($settings->get('license.title', 'License') ?? 'License'),
                'privacy_show_in_footer' => (bool) $settings->get('privacy.show_in_footer', false),
                'privacy_title_footer' => (string) ($settings->get('privacy.title', 'Privacy Policy') ?? 'Privacy Policy'),
                'cookie_show_in_footer' => (bool) $settings->get('cookie.show_in_footer', false),
                'cookie_title_footer' => (string) ($settings->get('cookie.title', 'Cookie Policy') ?? 'Cookie Policy'),

                // Language & date
                'date_format' => $settings->get('date.format', 'Y-m-d'),
                'site_language' => (string) ($settings->get('site.language', 'en') ?? 'en'),
                'admin_language' => (string) ($settings->get('admin.language', 'en') ?? 'en'),

                // Debug flags
                'admin_debug' => (bool) $settings->get('admin.debug_logs', false),

                // Frontend settings
                'dark_mode' => (bool) $settings->get('frontend.dark_mode', false),
                'allow_theme_toggle' => (bool) $settings->get('frontend.allow_theme_toggle', true),
                'custom_css' => (string) $settings->get('frontend.custom_css', ''),

                // Cookie banner
                'cookie_banner_enabled' => (bool) $settings->get('privacy.cookie_banner_enabled', true),
                'custom_js_essential' => $settings->get('privacy.custom_js_essential', ''),
                'custom_js_analytics' => $settings->get('privacy.custom_js_analytics', ''),
                'custom_js_marketing' => $settings->get('privacy.custom_js_marketing', ''),
                'show_analytics' => (bool) $settings->get('cookie_banner.show_analytics', false),
                'show_marketing' => (bool) $settings->get('cookie_banner.show_marketing', false),

                // Lightbox & interaction
                'lightbox_show_exif' => (bool) $settings->get('lightbox.show_exif', true),
                'disable_right_click' => (bool) $settings->get('frontend.disable_right_click', true),

                // Navigation
                'show_tags_in_header' => (bool) $settings->get('navigation.show_tags_in_header', false),
            ];

            // SEO globals (frontend only)
            if (!$isAdminRoute) {
                $globals['og_site_name'] = $settings->get('seo.og_site_name', $siteTitle);
                $globals['og_type'] = $settings->get('seo.og_type', 'website');
                $globals['og_locale'] = $settings->get('seo.og_locale', 'en_US');
                $globals['twitter_card'] = $settings->get('seo.twitter_card', 'summary_large_image');
                $globals['twitter_site'] = $settings->get('seo.twitter_site', '');
                $globals['twitter_creator'] = $settings->get('seo.twitter_creator', '');
                $globals['robots'] = $settings->get('seo.robots_default', 'index,follow');

                // Schema/structured data settings — the COMPLETE shape every
                // template reads (see buildSchemaArray). A per-page `schema` in a
                // controller render context shadows this global; both must carry
                // the same keys so JSON-LD never references undefined values.
                $schema = self::buildSchemaArray($settings);
                $canonicalBase = (string) ($settings->get('seo.canonical_base_url', '') ?? '');
                $schema['canonical_base'] = rtrim($canonicalBase, '/');
                $globals['schema'] = $schema;

                $globals['analytics_gtag'] = $settings->get('seo.analytics_gtag', '');
                $globals['analytics_gtm'] = $settings->get('seo.analytics_gtm', '');

                // Search-engine ownership verification meta tags (rendered in
                // _layout only when non-empty). Wiring the globals is what makes
                // the <meta> tags real rather than always-empty dead markup.
                $globals['google_verification'] = $settings->get('seo.google_verification', '');
                $globals['bing_verification'] = $settings->get('seo.bing_verification', '');
            }

            return $globals;
        } catch (\Throwable $e) {
            Logger::warning('TwigGlobalsCache: Failed to build globals', [
                'error' => $e->getMessage(),
                'isAdmin' => $isAdminRoute,
            ], 'cache');

            // Return safe defaults on error
            return self::getDefaults($basePath);
        }
    }

    /**
     * Build the COMPLETE structured-data (`schema`) array from SEO settings.
     *
     * This is the single source of truth for the shape templates rely on
     * (_layout.twig, _breadcrumbs.twig, home JSON-LD): every key the templates
     * read is always present so JSON-LD never references an undefined value.
     * Shared by {@see buildGlobals} (the Twig global) and
     * PageController::buildSeo (the per-page render context), so the two never
     * drift apart.
     *
     * When `seo.schema_enabled` is false the array is returned fully neutralised
     * (all names blank, all *_enabled false) so no JSON-LD block renders,
     * regardless of how a template gates output.
     *
     * NOTE: `canonical_base` is intentionally NOT set here — the caller supplies
     * the request-accurate absolute base (buildSeo) or the settings fallback
     * (buildGlobals).
     *
     * @return array<string, mixed>
     */
    public static function buildSchemaArray(SettingsService $settings): array
    {
        $enabled = (bool) $settings->get('seo.schema_enabled', true);

        if (!$enabled) {
            // Gate: neutralised shape — every gate condition evaluates false.
            return [
                'enabled' => false,
                'schema_enabled' => false,
                'breadcrumbs_enabled' => false,
                'author_name' => '',
                'author_url' => '',
                'organization_name' => '',
                'organization_url' => '',
                'photographer_job_title' => '',
                'photographer_services' => '',
                'photographer_same_as' => '',
                'image_copyright_notice' => '',
                'image_license_url' => '',
                'image_acquire_license_page' => '',
                'local_business_enabled' => false,
                'local_business_type' => '',
                'local_business_name' => '',
                'local_business_address' => '',
                'local_business_city' => '',
                'local_business_country' => '',
                'local_business_postal_code' => '',
                'local_business_phone' => '',
                'local_business_price_range' => '',
                'local_business_opening_hours' => '',
                'local_business_geo_lat' => '',
                'local_business_geo_lng' => '',
            ];
        }

        return [
            'enabled' => true,
            'schema_enabled' => true,
            'breadcrumbs_enabled' => (bool) $settings->get('seo.breadcrumbs_enabled', true),
            'author_name' => (string) ($settings->get('seo.author_name', '') ?? ''),
            'author_url' => (string) ($settings->get('seo.author_url', '') ?? ''),
            'organization_name' => (string) ($settings->get('seo.organization_name', '') ?? ''),
            'organization_url' => (string) ($settings->get('seo.organization_url', '') ?? ''),
            'photographer_job_title' => (string) ($settings->get('seo.photographer_job_title', 'Professional Photographer') ?? 'Professional Photographer'),
            'photographer_services' => (string) ($settings->get('seo.photographer_services', 'Professional Photography Services') ?? 'Professional Photography Services'),
            'photographer_same_as' => (string) ($settings->get('seo.photographer_same_as', '') ?? ''),
            'image_copyright_notice' => (string) ($settings->get('seo.image_copyright_notice', '') ?? ''),
            'image_license_url' => (string) ($settings->get('seo.image_license_url', '') ?? ''),
            'image_acquire_license_page' => (string) ($settings->get('seo.image_acquire_license_page', '') ?? ''),
            'local_business_enabled' => (bool) $settings->get('seo.local_business_enabled', false),
            'local_business_type' => (string) ($settings->get('seo.local_business_type', 'ProfessionalService') ?? 'ProfessionalService'),
            'local_business_name' => (string) ($settings->get('seo.local_business_name', '') ?? ''),
            'local_business_address' => (string) ($settings->get('seo.local_business_address', '') ?? ''),
            'local_business_city' => (string) ($settings->get('seo.local_business_city', '') ?? ''),
            'local_business_country' => (string) ($settings->get('seo.local_business_country', '') ?? ''),
            'local_business_postal_code' => (string) ($settings->get('seo.local_business_postal_code', '') ?? ''),
            'local_business_phone' => (string) ($settings->get('seo.local_business_phone', '') ?? ''),
            'local_business_price_range' => (string) ($settings->get('seo.local_business_price_range', '$$') ?? '$$'),
            'local_business_opening_hours' => (string) ($settings->get('seo.local_business_opening_hours', '') ?? ''),
            'local_business_geo_lat' => (string) ($settings->get('seo.local_business_geo_lat', '') ?? ''),
            'local_business_geo_lng' => (string) ($settings->get('seo.local_business_geo_lng', '') ?? ''),
        ];
    }

    /**
     * Get a slug setting with fallback.
     */
    private static function getSlug(SettingsService $settings, string $key, string $default): string
    {
        $slug = (string) ($settings->get($key, $default) ?? $default);
        return $slug !== '' ? $slug : $default;
    }

    /**
     * Default values when settings cannot be loaded.
     * Public for use in index.php fallback paths.
     */
    public static function getDefaults(string $basePath): array
    {
        return [
            'about_url' => $basePath . '/about',
            'galleries_url' => $basePath . '/galleries',
            'license_url' => $basePath . '/license',
            'privacy_url' => $basePath . '/privacy-policy',
            'cookie_url' => $basePath . '/cookie-policy',
            'site_title' => 'Cimaise',
            'site_logo' => null,
            'logo_type' => 'text',
            'site_copyright' => '',
            'license_show_in_footer' => false,
            'license_title_footer' => 'License',
            'privacy_show_in_footer' => false,
            'privacy_title_footer' => 'Privacy Policy',
            'cookie_show_in_footer' => false,
            'cookie_title_footer' => 'Cookie Policy',
            'date_format' => 'Y-m-d',
            'site_language' => 'en',
            'admin_language' => 'en',
            'admin_debug' => false,
            'dark_mode' => false,
            'allow_theme_toggle' => true,
            'custom_css' => '',
            'cookie_banner_enabled' => true,
            'custom_js_essential' => '',
            'custom_js_analytics' => '',
            'custom_js_marketing' => '',
            'show_analytics' => false,
            'show_marketing' => false,
            'lightbox_show_exif' => true,
            'disable_right_click' => true,
            'show_tags_in_header' => false,
            'og_site_name' => 'Cimaise',
            'og_type' => 'website',
            'og_locale' => 'en_US',
            'twitter_card' => 'summary_large_image',
            'twitter_site' => '',
            'twitter_creator' => '',
            'robots' => 'index,follow',
            'schema' => [
                'enabled' => true,
                'schema_enabled' => true,
                'breadcrumbs_enabled' => true,
                'author_name' => '',
                'author_url' => '',
                'organization_name' => '',
                'organization_url' => '',
                'photographer_job_title' => 'Professional Photographer',
                'photographer_services' => 'Professional Photography Services',
                'photographer_same_as' => '',
                'image_copyright_notice' => '',
                'image_license_url' => '',
                'image_acquire_license_page' => '',
                'local_business_enabled' => false,
                'local_business_type' => 'ProfessionalService',
                'local_business_name' => '',
                'local_business_address' => '',
                'local_business_city' => '',
                'local_business_country' => '',
                'local_business_postal_code' => '',
                'local_business_phone' => '',
                'local_business_price_range' => '$$',
                'local_business_opening_hours' => '',
                'local_business_geo_lat' => '',
                'local_business_geo_lng' => '',
                'canonical_base' => '',
            ],
            'analytics_gtag' => '',
            'analytics_gtm' => '',
        ];
    }
}
