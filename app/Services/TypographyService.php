<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Typography Service
 *
 * Manages font selection and CSS variable generation for the frontend.
 * Uses local fonts (GDPR compliant) downloaded from Google Fonts.
 */
class TypographyService
{
    private SettingsService $settings;

    /**
     * Available serif fonts organized by category
     * Each font includes name, weights, and category for UI grouping
     */
    public const SERIF_FONTS = [
        // Editorial / Classic
        'playfair-display' => [
            'name' => 'Playfair Display',
            'weights' => [400, 500, 600, 700],
            'category' => 'editorial',
            'description' => 'Refined, high contrast. Perfect for titles.',
        ],
        'cormorant-garamond' => [
            'name' => 'Cormorant Garamond',
            'weights' => [400, 500, 600, 700],
            'category' => 'editorial',
            'description' => 'Fine, elegant. Beautiful for artistic portfolios.',
        ],
        'eb-garamond' => [
            'name' => 'EB Garamond',
            'weights' => [400, 500, 600, 700],
            'category' => 'editorial',
            'description' => 'Timeless classic. Safe choice for any project.',
        ],
        'libre-baskerville' => [
            'name' => 'Libre Baskerville',
            'weights' => [400, 700],
            'category' => 'editorial',
            'description' => 'Sober elegance, very readable.',
        ],
        'lora' => [
            'name' => 'Lora',
            'weights' => [400, 500, 600, 700],
            'category' => 'editorial',
            'description' => 'Clean, elegant, never invasive.',
        ],
        'crimson-text' => [
            'name' => 'Crimson Text',
            'weights' => [400, 600, 700],
            'category' => 'editorial',
            'description' => 'Classic, less dated than Garamond.',
        ],
        'spectral' => [
            'name' => 'Spectral',
            'weights' => [400, 500, 600, 700],
            'category' => 'editorial',
            'description' => 'Modern with print feeling. Well balanced.',
        ],
        'domine' => [
            'name' => 'Domine',
            'weights' => [400, 500, 600, 700],
            'category' => 'editorial',
            'description' => 'Strong serif for headlines.',
        ],
        'old-standard-tt' => [
            'name' => 'Old Standard TT',
            'weights' => [400, 700],
            'category' => 'editorial',
            'description' => '1930s editorial vibe. Powerful character.',
        ],
        'quattrocento' => [
            'name' => 'Quattrocento',
            'weights' => [400, 700],
            'category' => 'editorial',
            'description' => 'Renaissance inspired, classic elegance.',
        ],

        // Fine Art / Display
        'dm-serif-display' => [
            'name' => 'DM Serif Display',
            'weights' => [400],
            'category' => 'display',
            'description' => 'Elegant but friendly. Great for titles.',
        ],
        'alegreya' => [
            'name' => 'Alegreya',
            'weights' => [400, 500, 600, 700],
            'category' => 'display',
            'description' => 'Dynamic, versatile display serif.',
        ],
        'merriweather' => [
            'name' => 'Merriweather',
            'weights' => [400, 700],
            'category' => 'display',
            'description' => 'Highly readable, classic feeling.',
        ],
        'pt-serif' => [
            'name' => 'PT Serif',
            'weights' => [400, 700],
            'category' => 'display',
            'description' => 'Professional, neutral serif.',
        ],
        'abril-fatface' => [
            'name' => 'Abril Fatface',
            'weights' => [400],
            'category' => 'display',
            'description' => 'Bold display, magazine style.',
        ],
        'cinzel' => [
            'name' => 'Cinzel',
            'weights' => [400, 500, 600, 700],
            'category' => 'display',
            'description' => 'Classical, monumental. For conceptual work.',
        ],
        'yeseva-one' => [
            'name' => 'Yeseva One',
            'weights' => [400],
            'category' => 'display',
            'description' => 'Distinctive headline font.',
        ],
        'della-respira' => [
            'name' => 'Della Respira',
            'weights' => [400],
            'category' => 'display',
            'description' => 'Art nouveau inspired display.',
        ],
        'volkhov' => [
            'name' => 'Volkhov',
            'weights' => [400, 700],
            'category' => 'display',
            'description' => 'Low contrast, traditional feel.',
        ],

        // Modern / Fashion
        'fraunces' => [
            'name' => 'Fraunces',
            'weights' => [400, 500, 600, 700],
            'category' => 'modern',
            'description' => 'Modern serif with controlled personality.',
        ],
        'source-serif-4' => [
            'name' => 'Source Serif 4',
            'weights' => [400, 600, 700],
            'category' => 'modern',
            'description' => 'Professional, no frills. Adobe style.',
        ],
        'crimson-pro' => [
            'name' => 'Crimson Pro',
            'weights' => [400, 500, 600, 700],
            'category' => 'modern',
            'description' => 'Classic but modern. Well balanced.',
        ],
        'newsreader' => [
            'name' => 'Newsreader',
            'weights' => [400, 500, 600, 700],
            'category' => 'modern',
            'description' => 'Made for reading, visually refined.',
        ],
        'bodoni-moda' => [
            'name' => 'Bodoni Moda',
            'weights' => [400, 500, 600, 700],
            'category' => 'modern',
            'description' => 'Pure fashion. High contrast, strong character.',
        ],
        'italiana' => [
            'name' => 'Italiana',
            'weights' => [400],
            'category' => 'modern',
            'description' => 'Minimal, luxury brand feel.',
        ],
    ];

    /**
     * Available sans-serif fonts
     */
    public const SANS_FONTS = [
        // Helvetica-like / Clean
        'inter' => [
            'name' => 'Inter',
            'weights' => [400, 500, 600, 700],
            'category' => 'clean',
            'description' => 'Modern, neutral. Perfect for UI.',
        ],
        'dm-sans' => [
            'name' => 'DM Sans',
            'weights' => [400, 500, 700],
            'category' => 'clean',
            'description' => 'Clean, airy. Perfect for galleries.',
        ],
        'manrope' => [
            'name' => 'Manrope',
            'weights' => [400, 500, 600, 700],
            'category' => 'clean',
            'description' => 'Modern, slightly premium feel.',
        ],
        'plus-jakarta-sans' => [
            'name' => 'Plus Jakarta Sans',
            'weights' => [400, 500, 600, 700],
            'category' => 'clean',
            'description' => 'Contemporary design, elegant.',
        ],
        'noto-sans' => [
            'name' => 'Noto Sans',
            'weights' => [400, 500, 600, 700],
            'category' => 'clean',
            'description' => 'Super readable, multilingual.',
        ],

        // Geometric / Modern
        'urbanist' => [
            'name' => 'Urbanist',
            'weights' => [400, 500, 600, 700],
            'category' => 'geometric',
            'description' => 'Modern, soft. Great for descriptions.',
        ],
        'space-grotesk' => [
            'name' => 'Space Grotesk',
            'weights' => [400, 500, 600, 700],
            'category' => 'geometric',
            'description' => 'Geometric but human. Pairs with classic serif.',
        ],
        'sora' => [
            'name' => 'Sora',
            'weights' => [400, 500, 600, 700],
            'category' => 'geometric',
            'description' => 'Clean with personality. Works with Bodoni.',
        ],
        'archivo' => [
            'name' => 'Archivo',
            'weights' => [400, 500, 600, 700],
            'category' => 'geometric',
            'description' => 'Industrial but ordered. Good for captions.',
        ],
        'montserrat' => [
            'name' => 'Montserrat',
            'weights' => [400, 500, 600, 700],
            'category' => 'geometric',
            'description' => 'Popular choice, works for secondary titles.',
        ],

        // Readable / Text
        'lexend' => [
            'name' => 'Lexend',
            'weights' => [400, 500, 600, 700],
            'category' => 'readable',
            'description' => 'Ultra readable, perfect for long text.',
        ],
        'roboto' => [
            'name' => 'Roboto',
            'weights' => [400, 500, 700],
            'category' => 'readable',
            'description' => 'Google standard, universal.',
        ],
        'open-sans' => [
            'name' => 'Open Sans',
            'weights' => [400, 600, 700],
            'category' => 'readable',
            'description' => 'Readable, universal.',
        ],
        'lato' => [
            'name' => 'Lato',
            'weights' => [400, 700],
            'category' => 'readable',
            'description' => 'Elegant, warm.',
        ],
        'source-sans-3' => [
            'name' => 'Source Sans 3',
            'weights' => [400, 600, 700],
            'category' => 'readable',
            'description' => 'Adobe professional sans.',
        ],
    ];

    /**
     * Typography contexts with their defaults
     */
    public const CONTEXTS = [
        'headings' => [
            'label' => 'Headings',
            'description' => 'Site title, album titles, page headings (h1, h2, h3)',
            'default_font' => 'eb-garamond',
            'default_weight' => 600,
            'css_selector' => 'h1, h2, h3, h4, h5, h6, .site-title, .album-title, .page-title',
        ],
        'body' => [
            'label' => 'Body Text',
            'description' => 'Paragraphs, descriptions, album text',
            'default_font' => 'inter',
            'default_weight' => 400,
            'css_selector' => 'body, p, .album-description, .page-content, .bio-text',
        ],
        'captions' => [
            'label' => 'Captions & Metadata',
            'description' => 'Image captions, EXIF data, small text',
            'default_font' => 'inter',
            'default_weight' => 400,
            'css_selector' => '.caption, .image-caption, .exif-data, .metadata, figcaption',
        ],
        'navigation' => [
            'label' => 'Navigation',
            'description' => 'Menu items, links, buttons',
            'default_font' => 'inter',
            'default_weight' => 500,
            'css_selector' => 'nav, .nav-link, .menu-item, .btn, button',
        ],
    ];

    public function __construct(SettingsService $settings)
    {
        $this->settings = $settings;
    }

    /**
     * Get all available fonts organized by type
     */
    public function getAllFonts(): array
    {
        return [
            'serif' => self::SERIF_FONTS,
            'sans' => self::SANS_FONTS,
        ];
    }

    /**
     * Get current typography settings for all contexts
     */
    public function getTypography(): array
    {
        $result = [];
        foreach (self::CONTEXTS as $context => $config) {
            $result[$context] = [
                'font' => $this->settings->get("typography.{$context}_font", $config['default_font']),
                'weight' => (int) $this->settings->get("typography.{$context}_weight", $config['default_weight']),
            ];
        }
        return $result;
    }

    /**
     * Save typography settings
     */
    public function saveTypography(array $data): void
    {
        foreach (array_keys(self::CONTEXTS) as $context) {
            $currentFont = $this->settings->get("typography.{$context}_font", self::CONTEXTS[$context]['default_font']);
            $fontSlug = $this->sanitizeFontSlug($currentFont);
            if (isset($data["{$context}_font"])) {
                $fontSlug = $this->sanitizeFontSlug($data["{$context}_font"]);
                $this->settings->set("typography.{$context}_font", $fontSlug);
            }
            if (isset($data["{$context}_weight"])) {
                $weight = (int) $data["{$context}_weight"];
                // Validate weight is in reasonable range (will be adjusted to closest available at render time)
                if ($weight >= 100 && $weight <= 900) {
                    $fontData = $this->getFontBySlug($fontSlug);
                    if ($fontData && !in_array($weight, $fontData['weights'], true)) {
                        $weight = $this->getClosestWeight($fontData['weights'], $weight);
                    }
                    $this->settings->set("typography.{$context}_weight", $weight);
                }
            }
        }
    }

    /**
     * Reset typography to defaults
     */
    public function resetToDefaults(): void
    {
        foreach (self::CONTEXTS as $context => $config) {
            $this->settings->set("typography.{$context}_font", $config['default_font']);
            $this->settings->set("typography.{$context}_weight", $config['default_weight']);
        }
    }

    /**
     * Get font data by slug
     */
    public function getFontBySlug(string $slug): ?array
    {
        if (isset(self::SERIF_FONTS[$slug])) {
            return array_merge(self::SERIF_FONTS[$slug], ['type' => 'serif']);
        }
        if (isset(self::SANS_FONTS[$slug])) {
            return array_merge(self::SANS_FONTS[$slug], ['type' => 'sans']);
        }
        return null;
    }

    /**
     * Check if a font is serif
     */
    public function isSerif(string $slug): bool
    {
        return isset(self::SERIF_FONTS[$slug]);
    }

    /**
     * Generate CSS with @font-face declarations for used fonts only
     */
    public function generateFontFacesCss(string $basePath = ''): string
    {
        $typography = $this->getTypography();
        $usedFonts = [];

        // Collect unique fonts and their weights
        foreach ($typography as $config) {
            $slug = $config['font'];
            if (!isset($usedFonts[$slug])) {
                $usedFonts[$slug] = [];
            }
            if (!in_array($config['weight'], $usedFonts[$slug])) {
                $usedFonts[$slug][] = $config['weight'];
            }
        }

        $css = "/* Typography - Font Faces */\n\n";

        foreach ($usedFonts as $slug => $weights) {
            $fontData = $this->getFontBySlug($slug);
            if (!$fontData) {
                continue;
            }

            $fontName = $fontData['name'];
            sort($weights);

            foreach ($weights as $weight) {
                // Check if this weight is available for this font
                if (!in_array($weight, $fontData['weights'])) {
                    // Use closest available weight
                    $weight = $this->getClosestWeight($fontData['weights'], $weight);
                }

                $css .= "@font-face {\n";
                $css .= "  font-family: '{$fontName}';\n";
                $css .= "  font-style: normal;\n";
                $css .= "  font-weight: {$weight};\n";
                $css .= "  font-display: swap;\n";
                $css .= "  src: url('{$basePath}/fonts/{$slug}/{$slug}-{$weight}.woff2') format('woff2');\n";
                $css .= "}\n\n";
            }
        }

        return $css;
    }

    /**
     * Generate CSS custom properties for typography
     */
    public function generateCssVariables(): string
    {
        $typography = $this->getTypography();
        $css = "/* Typography - CSS Variables */\n:root {\n";

        foreach ($typography as $context => $config) {
            $fontData = $this->getFontBySlug($config['font']);
            if (!$fontData) {
                continue;
            }

            $fontName = $fontData['name'];
            $fallback = $fontData['type'] === 'serif' ? 'Georgia, serif' : '-apple-system, BlinkMacSystemFont, sans-serif';
            $varName = str_replace('_', '-', $context);

            $css .= "  --font-{$varName}: '{$fontName}', {$fallback};\n";
            $css .= "  --font-{$varName}-weight: {$config['weight']};\n";
        }

        $css .= "}\n\n";

        // Add CSS rules for each context
        $css .= "/* Typography - Applied Styles */\n";

        foreach (self::CONTEXTS as $context => $contextConfig) {
            $varName = str_replace('_', '-', $context);
            $selector = $contextConfig['css_selector'];

            $css .= "{$selector} {\n";
            $css .= "  font-family: var(--font-{$varName}) !important;\n";
            $css .= "  font-weight: var(--font-{$varName}-weight) !important;\n";
            $css .= "}\n\n";
        }

        return $css;
    }

    /**
     * Generate complete typography CSS (font-faces + variables + rules)
     */
    public function generateFullCss(string $basePath = ''): string
    {
        return $this->generateFontFacesCss($basePath) . $this->generateCssVariables();
    }

    /**
     * Write typography CSS to file
     */
    public function writeCssFile(string $outputPath, string $basePath = ''): bool
    {
        $css = $this->generateFullCss($basePath);
        $result = @file_put_contents($outputPath, $css);
        if ($result === false) {
            error_log("TypographyService: Failed to write CSS file to {$outputPath}");
        }
        return $result !== false;
    }

    /**
     * Get list of fonts that need to be downloaded
     */
    public function getRequiredFonts(): array
    {
        $typography = $this->getTypography();
        $fonts = [];

        foreach ($typography as $config) {
            $slug = $config['font'];
            $fontData = $this->getFontBySlug($slug);

            if (!$fontData) {
                continue;
            }

            if (!isset($fonts[$slug])) {
                $fonts[$slug] = [
                    'name' => $fontData['name'],
                    'weights' => [],
                ];
            }

            if (!in_array($config['weight'], $fonts[$slug]['weights'])) {
                $fonts[$slug]['weights'][] = $config['weight'];
            }
        }

        return $fonts;
    }

    /**
     * Sanitize font slug to prevent path traversal
     */
    private function sanitizeFontSlug(string $slug): string
    {
        // Only allow lowercase letters, numbers, and hyphens
        // Null coalesce handles potential PCRE error in PHP 8.x
        $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower($slug)) ?? '';

        // Verify it's a valid font
        if (!isset(self::SERIF_FONTS[$slug]) && !isset(self::SANS_FONTS[$slug])) {
            return 'inter'; // Default fallback
        }

        return $slug;
    }

    /**
     * Get closest available weight
     */
    private function getClosestWeight(array $available, int $target): int
    {
        // Defensive check for empty array
        if (empty($available)) {
            return 400; // Default to regular weight
        }

        $closest = $available[0];
        $minDiff = abs($target - $closest);

        foreach ($available as $weight) {
            $diff = abs($target - $weight);
            if ($diff < $minDiff) {
                $minDiff = $diff;
                $closest = $weight;
            }
        }

        return $closest;
    }
}
