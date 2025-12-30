<?php
declare(strict_types=1);

namespace CustomTemplatesPro\Services;

/**
 * Plugin-specific translation service
 *
 * This service loads translations from the plugin's own translations/ folder,
 * allowing plugin authors to maintain their own translation files independently.
 *
 * Supports Italian (it) and English (en) as fallback for all other languages.
 */
class PluginTranslationService
{
    private array $translations = [];
    private string $language = 'en';
    private bool $loaded = false;
    private string $translationsDir;

    public function __construct()
    {
        $this->translationsDir = dirname(__DIR__) . '/translations';
    }

    /**
     * Set the language based on admin panel language setting
     * Falls back to English for any language other than Italian
     */
    public function setLanguage(string $language): void
    {
        $language = strtolower(trim($language));

        // Only Italian is supported as alternative, all others fall back to English
        $this->language = ($language === 'it') ? 'it' : 'en';

        // Invalidate cache when language changes
        $this->loaded = false;
        $this->translations = [];
    }

    /**
     * Get current language
     */
    public function getLanguage(): string
    {
        return $this->language;
    }

    /**
     * Get a translation by key with optional parameter interpolation
     *
     * @param string $key The translation key (e.g., 'ctp.title')
     * @param array $params Parameters to interpolate (e.g., ['name' => 'value'])
     * @param string|null $default Default value if key not found
     * @return string The translated string
     */
    public function get(string $key, array $params = [], ?string $default = null): string
    {
        $this->loadTranslations();

        $value = $this->translations[$key] ?? $default ?? $key;

        // Parameter interpolation: {param} -> value
        if (!empty($params)) {
            foreach ($params as $name => $val) {
                $value = str_replace('{' . $name . '}', (string)$val, $value);
            }
        }

        return $value;
    }

    /**
     * Check if a translation key exists
     */
    public function has(string $key): bool
    {
        $this->loadTranslations();
        return isset($this->translations[$key]);
    }

    /**
     * Get all translations
     */
    public function all(): array
    {
        $this->loadTranslations();
        return $this->translations;
    }

    /**
     * Load translations from JSON file
     */
    private function loadTranslations(): void
    {
        if ($this->loaded) {
            return;
        }

        $filePath = $this->translationsDir . '/' . $this->language . '.json';
        $fallbackPath = $this->translationsDir . '/en.json';

        // Fall back to English if language file doesn't exist
        if (!file_exists($filePath)) {
            $filePath = $fallbackPath;
        }

        if (!file_exists($filePath)) {
            $this->loaded = true;
            return;
        }

        $content = @file_get_contents($filePath);
        if (!$content) {
            $this->loaded = true;
            return;
        }

        $data = json_decode($content, true);
        if (!\is_array($data)) {
            $this->loaded = true;
            return;
        }

        // Flatten the structure: extract translations from 'plugin' section
        if (isset($data['plugin']) && \is_array($data['plugin'])) {
            $this->translations = $data['plugin'];
        }

        $this->loaded = true;
    }

    /**
     * Get available languages for this plugin
     *
     * @return array Array of ['code' => 'en', 'name' => 'English', ...]
     */
    public function getAvailableLanguages(): array
    {
        $languages = [];

        foreach (glob($this->translationsDir . '/*.json') as $file) {
            $content = @file_get_contents($file);
            if (!$content) {
                continue;
            }

            $data = json_decode($content, true);
            if (!\is_array($data) || !isset($data['_meta'])) {
                continue;
            }

            $languages[] = [
                'code' => $data['_meta']['code'] ?? basename($file, '.json'),
                'name' => $data['_meta']['language'] ?? ucfirst(basename($file, '.json')),
                'version' => $data['_meta']['version'] ?? '1.0.0',
            ];
        }

        return $languages;
    }
}
