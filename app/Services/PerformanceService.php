<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Database;

/**
 * Service for managing frontend performance optimizations
 */
class PerformanceService
{
    public function __construct(
        private Database $db,
        private SettingsService $settings,
        private string $basePath
    ) {}

    /**
     * Get resource hints (dns-prefetch, preconnect) based on settings
     */
    public function getResourceHints(): array
    {
        $hints = [
            'dns-prefetch' => [],
            'preconnect' => [],
            'preload' => []
        ];

        // Always preconnect to own domain for faster resource loading
        $hints['preconnect'][] = ['href' => '', 'crossorigin' => false];

        // Google Fonts (if using custom typography)
        if ($this->usesGoogleFonts()) {
            $hints['preconnect'][] = ['href' => 'https://fonts.googleapis.com', 'crossorigin' => false];
            $hints['preconnect'][] = ['href' => 'https://fonts.gstatic.com', 'crossorigin' => true];
        }

        // reCAPTCHA
        if ($this->settings->get('recaptcha.enabled', false)) {
            $hints['dns-prefetch'][] = ['href' => 'https://www.google.com'];
            $hints['dns-prefetch'][] = ['href' => 'https://www.gstatic.com'];
        }

        // Analytics domains (if custom analytics JS is configured)
        $analyticsJs = (string) ($this->settings->get('privacy.custom_js_analytics', '') ?? '');
        if ($analyticsJs !== '' && (str_contains($analyticsJs, 'google-analytics') || str_contains($analyticsJs, 'googletagmanager'))) {
            $hints['dns-prefetch'][] = ['href' => 'https://www.googletagmanager.com'];
            $hints['dns-prefetch'][] = ['href' => 'https://www.google-analytics.com'];
        }

        // CDN domains (if configured)
        $cdnUrl = (string) ($this->settings->get('cdn.url', '') ?? '');
        if ($cdnUrl !== '') {
            $parsed = parse_url($cdnUrl);
            if ($parsed && isset($parsed['scheme']) && isset($parsed['host'])) {
                $hints['preconnect'][] = [
                    'href' => $parsed['scheme'] . '://' . $parsed['host'],
                    'crossorigin' => true
                ];
            }
        }

        // Preload critical CSS
        $hints['preload'][] = [
            'href' => $this->basePath . '/assets/app.css',
            'as' => 'style',
            'type' => 'text/css'
        ];

        return $hints;
    }

    /**
     * Check if site uses Google Fonts
     */
    private function usesGoogleFonts(): bool
    {
        // Check if custom CSS contains Google Fonts imports
        $customCss = (string) ($this->settings->get('frontend.custom_css', '') ?? '');
        if ($customCss !== '' && (str_contains($customCss, 'fonts.googleapis.com') || str_contains($customCss, 'fonts.gstatic.com'))) {
            return true;
        }

        // Check typography settings if available
        try {
            $stmt = $this->db->pdo()->query("SELECT value FROM settings WHERE key = 'typography.font_family'");
            $fontFamily = $stmt->fetchColumn();
            if ($fontFamily && is_string($fontFamily) && str_contains($fontFamily, 'google')) {
                return true;
            }
        } catch (\Throwable $e) {
            // Table might not exist yet
        }

        return false;
    }

    /**
     * Get defer/async configuration for scripts
     */
    public function getScriptLoadingStrategy(): array
    {
        return [
            // Admin scripts - load normally (needed for functionality)
            'admin.js' => 'normal',

            // App CSS - preload
            'app.css' => 'preload',

            // Hero/Home scripts - defer (not critical for initial render)
            'js/hero.js' => 'defer',
            'js/home.js' => 'defer',
            'js/home-modern.js' => 'defer',
            'js/home-gallery.js' => 'defer',

            // Smooth scroll - defer (progressive enhancement)
            'js/smooth-scroll.js' => 'defer',

            // Vendor scripts
            'photoswipe' => 'defer',
            'masonry' => 'defer',
        ];
    }

    /**
     * Get fetchpriority for images based on position
     */
    public function getImagePriority(int $index, string $context = 'gallery'): string
    {
        // First image in gallery/album = high priority (LCP candidate)
        if ($index === 0 && in_array($context, ['gallery', 'album', 'hero'])) {
            return 'high';
        }

        // Images 1-3 = auto (browser decides)
        if ($index <= 3) {
            return 'auto';
        }

        // Rest = low priority (below fold)
        return 'low';
    }

    /**
     * Generate optimized picture sizes attribute based on layout
     */
    public function getSizesAttribute(string $layout = 'default'): string
    {
        return match($layout) {
            'hero' => '100vw',
            'full-width' => '100vw',
            'magazine' => '(min-width: 1024px) 50vw, 100vw',
            'masonry' => '(min-width: 1280px) 33vw, (min-width: 768px) 50vw, 100vw',
            'grid' => '(min-width: 1280px) 25vw, (min-width: 768px) 33vw, 50vw',
            default => '(min-width: 1024px) 70vw, 95vw'
        };
    }

    /**
     * Check if critical resources should be inlined
     */
    public function shouldInlineCriticalCSS(): bool
    {
        return $this->settings->get('performance.inline_critical_css', false);
    }

    /**
     * Get performance metrics configuration
     */
    public function getPerformanceConfig(): array
    {
        return [
            'lazy_loading' => true,
            'async_decoding' => true,
            'resource_hints' => $this->getResourceHints(),
            'defer_scripts' => true,
            'preload_critical' => true,
            'image_priorities' => true,
        ];
    }
}
