<?php
declare(strict_types=1);

/**
 * Global helper functions for the application
 */

if (!function_exists('trans')) {
    /**
     * Translate a text key using the TranslationService
     *
     * This function provides access to translations from PHP code (controllers, services)
     * It uses the same translation system as the Twig trans() function
     *
     * @param string $key The translation key (e.g., 'admin.flash.csrf_error')
     * @param array $params Optional parameters for interpolation (e.g., ['count' => 5])
     * @param string|null $default Optional default value if key not found
     * @return string The translated string
     */
    function trans(string $key, array $params = [], ?string $default = null): string
    {
        // Use GLOBALS for reliable access across all scopes
        $translationService = $GLOBALS['translationService'] ?? null;

        if ($translationService instanceof \App\Services\TranslationService) {
            return $translationService->get($key, $params, $default);
        }

        // Fallback: return key or default if service not available
        return $default ?? $key;
    }
}
