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
    private const CACHE_KEY_PREFIX = 'twig_globals:';
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
            fn() => self::buildGlobals($db, $basePath, $isAdminRoute),
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
                'site_logo' => $settings->get('site.logo', null),
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

                // Schema/structured data settings
                $globals['schema'] = [
                    'enabled' => (bool) $settings->get('seo.schema_enabled', true),
                    'author_name' => $settings->get('seo.author_name', ''),
                    'author_url' => $settings->get('seo.author_url', ''),
                    'organization_name' => $settings->get('seo.organization_name', ''),
                    'organization_url' => $settings->get('seo.organization_url', ''),
                    'image_copyright_notice' => $settings->get('seo.image_copyright_notice', ''),
                    'image_license_url' => $settings->get('seo.image_license_url', ''),
                ];

                $globals['analytics_gtag'] = $settings->get('seo.analytics_gtag', '');
                $globals['analytics_gtm'] = $settings->get('seo.analytics_gtm', '');
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
                'author_name' => '',
                'author_url' => '',
                'organization_name' => '',
                'organization_url' => '',
                'image_copyright_notice' => '',
                'image_license_url' => '',
            ],
            'analytics_gtag' => '',
            'analytics_gtm' => '',
        ];
    }
}
