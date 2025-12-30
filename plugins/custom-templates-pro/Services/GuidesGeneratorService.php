<?php
declare(strict_types=1);

namespace CustomTemplatesPro\Services;

/**
 * Service for managing multilingual LLM guides
 *
 * Guides are stored in guides/{lang}/ folders:
 * - guides/en/ - English guides (default for all languages except Italian)
 * - guides/it/ - Italian guides
 */
class GuidesGeneratorService
{
    private string $pluginDir;
    private string $language = 'en';

    public function __construct()
    {
        $this->pluginDir = dirname(__DIR__);
    }

    /**
     * Set the language for guides
     * Falls back to English for any language other than Italian
     */
    public function setLanguage(string $language): void
    {
        $language = strtolower(trim($language));
        $this->language = ($language === 'it') ? 'it' : 'en';
    }

    /**
     * Get current language
     */
    public function getLanguage(): string
    {
        return $this->language;
    }

    /**
     * Generate all guides (creates directories if missing)
     * @deprecated Guides are now stored as static files in guides/{lang}/
     */
    public function generateAllGuides(): void
    {
        // Guides are now stored as static markdown files
        // This method is kept for backwards compatibility but does nothing
    }

    /**
     * Get the path to a guide in the current language
     *
     * @param string $type Guide type: 'gallery', 'album_page', or 'homepage'
     * @return string Full path to the guide file
     * @throws \InvalidArgumentException If type is invalid
     */
    public function getGuidePath(string $type): string
    {
        $filename = match ($type) {
            'gallery' => 'gallery-template-guide.md',
            'album_page' => 'album-page-guide.md',
            'homepage' => 'homepage-guide.md',
            default => throw new \InvalidArgumentException("Invalid guide type: {$type}")
        };

        // Try current language first
        $langPath = $this->pluginDir . '/guides/' . $this->language . '/' . $filename;

        if (file_exists($langPath)) {
            return $langPath;
        }

        // Fallback to English
        $fallbackPath = $this->pluginDir . '/guides/en/' . $filename;

        if (file_exists($fallbackPath)) {
            return $fallbackPath;
        }

        // Legacy fallback: check root guides folder (for backwards compatibility)
        $legacyPath = $this->pluginDir . '/guides/' . $filename;

        if (file_exists($legacyPath)) {
            return $legacyPath;
        }

        throw new \RuntimeException("Guide not found for type: {$type}");
    }

    /**
     * Get download filename for a guide
     *
     * @param string $type Guide type
     * @return string Filename with language suffix
     */
    public function getDownloadFilename(string $type): string
    {
        $baseName = match ($type) {
            'gallery' => 'gallery-template-guide',
            'album_page' => 'album-page-guide',
            'homepage' => 'homepage-guide',
            default => 'guide'
        };

        // Add language suffix for non-English
        if ($this->language !== 'en') {
            return $baseName . '-' . $this->language . '.md';
        }

        return $baseName . '.md';
    }

    /**
     * Check if guides exist for current language
     */
    public function guidesExist(): bool
    {
        try {
            $this->getGuidePath('gallery');
            $this->getGuidePath('album_page');
            $this->getGuidePath('homepage');
            return true;
        } catch (\RuntimeException $e) {
            return false;
        }
    }

    /**
     * Get available languages for guides
     *
     * @return array Array of language codes with guides
     */
    public function getAvailableLanguages(): array
    {
        $languages = [];
        $guidesDir = $this->pluginDir . '/guides';

        foreach (['en', 'it'] as $lang) {
            $langDir = $guidesDir . '/' . $lang;
            if (is_dir($langDir) && file_exists($langDir . '/gallery-template-guide.md')) {
                $languages[] = $lang;
            }
        }

        return $languages;
    }
}
