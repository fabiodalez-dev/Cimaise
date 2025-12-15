<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Support\Logger;
use PDO;

class TranslationService
{
    private array $cache = [];
    private bool $loaded = false;
    private string $language = 'en';
    private string $translationsDir;

    public function __construct(private Database $db)
    {
        $this->translationsDir = dirname(__DIR__, 2) . '/storage/translations';
    }

    /**
     * Set the active language code
     */
    public function setLanguage(string $code): void
    {
        // Sanitize and normalize to lowercase for case-sensitive filesystems
        $code = strtolower(preg_replace('/[^a-z0-9_-]/i', '', $code) ?: 'en');
        if ($this->language !== $code) {
            $this->language = $code;
            $this->loaded = false;
            $this->cache = [];
        }
    }

    /**
     * Get the active language code
     */
    public function getLanguage(): string
    {
        return $this->language;
    }

    /**
     * Get all translations
     */
    public function all(): array
    {
        $this->loadAll();
        return $this->cache;
    }

    /**
     * Get translations grouped by context
     */
    public function allGrouped(): array
    {
        $stmt = $this->db->pdo()->query(
            'SELECT `id`, `text_key`, `text_value`, `context`, `description`
             FROM frontend_texts
             ORDER BY `context`, `text_key`'
        );

        $grouped = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $context = $row['context'] ?? 'general';
            if (!isset($grouped[$context])) {
                $grouped[$context] = [];
            }
            $grouped[$context][] = $row;
        }
        return $grouped;
    }

    /**
     * Get a translation by key with optional parameter interpolation
     */
    public function get(string $key, array $params = [], ?string $default = null): string
    {
        $this->loadAll();

        $value = $this->cache[$key] ?? $default ?? $key;

        // Parameter interpolation: {param} -> value
        if (!empty($params)) {
            foreach ($params as $name => $val) {
                $value = str_replace('{' . $name . '}', (string)$val, $value);
            }
        }

        return $value;
    }

    /**
     * Get a single translation record by ID
     */
    public function find(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM frontend_texts WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Get a single translation record by key
     */
    public function findByKey(string $key): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM frontend_texts WHERE text_key = :key'
        );
        $stmt->execute([':key' => $key]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Set/update a translation
     */
    public function set(string $key, string $value, string $context = 'general', ?string $description = null): void
    {
        $replace = $this->db->replaceKeyword();
        $now = $this->db->nowExpression();

        $stmt = $this->db->pdo()->prepare(
            "{$replace} INTO frontend_texts (text_key, text_value, context, description, updated_at)
             VALUES (:key, :value, :context, :description, {$now})"
        );

        $stmt->execute([
            ':key' => $key,
            ':value' => $value,
            ':context' => $context,
            ':description' => $description
        ]);

        // Update cache
        $this->cache[$key] = $value;
    }

    /**
     * Update a translation by ID
     */
    public function update(int $id, array $data): bool
    {
        $now = $this->db->nowExpression();

        $stmt = $this->db->pdo()->prepare(
            "UPDATE frontend_texts
             SET text_value = :value, context = :context, description = :description, updated_at = {$now}
             WHERE id = :id"
        );

        $result = $stmt->execute([
            ':id' => $id,
            ':value' => $data['text_value'],
            ':context' => $data['context'] ?? 'general',
            ':description' => $data['description'] ?? null
        ]);

        // Invalidate cache
        $this->loaded = false;
        $this->cache = [];

        return $result;
    }

    /**
     * Create a new translation
     */
    public function create(array $data): int
    {
        $now = $this->db->nowExpression();

        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO frontend_texts (text_key, text_value, context, description, created_at, updated_at)
             VALUES (:key, :value, :context, :description, {$now}, {$now})"
        );

        $stmt->execute([
            ':key' => $data['text_key'],
            ':value' => $data['text_value'],
            ':context' => $data['context'] ?? 'general',
            ':description' => $data['description'] ?? null
        ]);

        // Invalidate cache
        $this->loaded = false;
        $this->cache = [];

        return (int)$this->db->pdo()->lastInsertId();
    }

    /**
     * Delete a translation
     */
    public function delete(int $id): bool
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM frontend_texts WHERE id = :id');
        $result = $stmt->execute([':id' => $id]);

        // Invalidate cache
        $this->loaded = false;
        $this->cache = [];

        return $result;
    }

    /**
     * Get all unique contexts
     */
    public function getContexts(): array
    {
        $stmt = $this->db->pdo()->query(
            'SELECT DISTINCT context FROM frontend_texts ORDER BY context'
        );
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Search translations
     */
    public function search(string $query): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM frontend_texts
             WHERE text_key LIKE :q OR text_value LIKE :q OR description LIKE :q
             ORDER BY context, text_key'
        );
        $stmt->execute([':q' => '%' . $query . '%']);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Load all translations into cache
     * First loads from JSON file (based on language), then overlays database translations
     */
    private function loadAll(): void
    {
        if ($this->loaded) {
            return;
        }

        // 1. Load from JSON file first (base translations)
        $this->loadFromJsonFile();

        // 2. Overlay database translations (customizations)
        try {
            $stmt = $this->db->pdo()->query('SELECT text_key, text_value FROM frontend_texts');
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $this->cache[$row['text_key']] = $row['text_value'];
            }
        } catch (\PDOException $e) {
            // Table might not exist yet
        }

        $this->loaded = true;
    }

    /**
     * Load translations from JSON file for current language
     */
    private function loadFromJsonFile(): void
    {
        $filePath = $this->translationsDir . '/' . $this->language . '.json';

        // Fall back to English if language file doesn't exist
        if (!file_exists($filePath)) {
            Logger::debug('Translation file not found, falling back to English', [
                'requested' => $this->language,
                'path' => $filePath
            ], 'translation');
            $filePath = $this->translationsDir . '/en.json';
        }

        if (!file_exists($filePath)) {
            Logger::warning('No translation file found', [
                'language' => $this->language,
                'path' => $filePath
            ], 'translation');
            return;
        }

        $content = @file_get_contents($filePath);
        if (!$content) {
            Logger::warning('Failed to read translation file', [
                'path' => $filePath
            ], 'translation');
            return;
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            Logger::warning('Invalid JSON in translation file', [
                'path' => $filePath,
                'json_error' => json_last_error_msg()
            ], 'translation');
            return;
        }

        // Flatten nested structure: category.key => value
        $loadedCount = 0;
        foreach ($data as $context => $translations) {
            if ($context === '_meta' || !is_array($translations)) {
                continue;
            }
            foreach ($translations as $key => $value) {
                if (is_string($value)) {
                    $this->cache[$key] = $value;
                    $loadedCount++;
                }
            }
        }

        Logger::debug('Loaded translations from JSON', [
            'path' => $filePath,
            'count' => $loadedCount
        ], 'translation');
    }

    /**
     * Clear the cache
     */
    public function clearCache(): void
    {
        $this->loaded = false;
        $this->cache = [];
    }

    /**
     * Get default translations for seeding
     */
    public static function getDefaults(): array
    {
        return [
            // Navigation
            ['nav.home', 'Home', 'navigation', 'Main navigation home link'],
            ['nav.galleries', 'Galleries', 'navigation', 'Main navigation galleries link'],
            ['nav.about', 'About', 'navigation', 'Main navigation about link'],
            ['nav.contact', 'Contact', 'navigation', 'Main navigation contact link'],
            ['nav.back', 'Back', 'navigation', 'Back button text'],
            ['nav.menu', 'Menu', 'navigation', 'Mobile menu button'],
            ['nav.close', 'Close', 'navigation', 'Close button text'],

            // Filters
            ['filter.all', 'All', 'filter', 'Show all items filter'],
            ['filter.category', 'Category', 'filter', 'Category filter label'],
            ['filter.categories', 'Categories', 'filter', 'Categories filter label'],
            ['filter.year', 'Year', 'filter', 'Year filter label'],
            ['filter.date', 'Date', 'filter', 'Date filter label'],
            ['filter.location', 'Location', 'filter', 'Location filter label'],
            ['filter.tag', 'Tag', 'filter', 'Tag filter label'],
            ['filter.tags', 'Tags', 'filter', 'Tags filter label'],
            ['filter.search', 'Search', 'filter', 'Search filter label'],
            ['filter.clear', 'Clear filters', 'filter', 'Clear all filters button'],
            ['filter.no_results', 'No results found', 'filter', 'No results message'],
            ['filter.results_count', '{count} results', 'filter', 'Results count with parameter'],
            ['filter.film', 'Film', 'filter', 'Film filter label'],
            ['filter.sort', 'Sort', 'filter', 'Sort filter label'],
            ['filter.process', 'Process', 'filter', 'Process filter label'],
            ['filter.apply', 'Apply', 'filter', 'Apply filters button'],
            ['filter.filter_images', 'Filter images:', 'filter', 'Filter images label'],
            ['filter.sort_latest', 'Latest First', 'filter', 'Sort by latest'],
            ['filter.sort_oldest', 'Oldest First', 'filter', 'Sort by oldest'],
            ['filter.sort_title_asc', 'Title A-Z', 'filter', 'Sort by title ascending'],
            ['filter.sort_title_desc', 'Title Z-A', 'filter', 'Sort by title descending'],
            ['filter.sort_date_new', 'Shoot Date (New)', 'filter', 'Sort by shoot date newest'],
            ['filter.sort_date_old', 'Shoot Date (Old)', 'filter', 'Sort by shoot date oldest'],
            ['filter.filters', 'Filters', 'filter', 'Filters section label'],
            ['filter.galleries_count', '{count} galleries', 'filter', 'Galleries count plural'],
            ['filter.gallery_count', '1 gallery', 'filter', 'Galleries count singular'],
            ['filter.no_galleries_title', 'No galleries found', 'filter', 'No galleries found title'],
            ['filter.no_galleries_text', 'We couldn\'t find any galleries matching your current filters. Try adjusting your search criteria or clearing all filters.', 'filter', 'No galleries found message'],

            // Album/Gallery
            ['album.photos', 'Photos', 'album', 'Photos label'],
            ['album.photo', 'Photo', 'album', 'Single photo label'],
            ['album.photo_count', '{count} photos', 'album', 'Photo count with parameter'],
            ['album.view_gallery', 'View Gallery', 'album', 'View gallery button'],
            ['album.view_all', 'View All', 'album', 'View all button'],
            ['album.download', 'Download', 'album', 'Download button'],
            ['album.share', 'Share', 'album', 'Share button'],
            ['album.info', 'Info', 'album', 'Info button'],
            ['album.details', 'Details', 'album', 'Details section title'],
            ['album.description', 'Description', 'album', 'Description label'],
            ['album.date', 'Date', 'album', 'Date label'],
            ['album.location', 'Location', 'album', 'Location label'],
            ['album.camera', 'Camera', 'album', 'Camera label'],
            ['album.lens', 'Lens', 'album', 'Lens label'],
            ['album.settings', 'Settings', 'album', 'Camera settings label'],
            ['album.empty', 'This gallery is empty', 'album', 'Empty gallery message'],
            ['album.private', 'Private Gallery', 'album', 'Private gallery label'],
            ['album.password_protected', 'Password Protected', 'album', 'Password protected label'],
            ['album.enter_password', 'Enter Password', 'album', 'Enter password prompt'],
            ['album.wrong_password', 'Wrong password', 'album', 'Wrong password error'],
            ['album.empty_message', 'Images will appear here once uploaded.', 'album', 'Empty gallery explanation'],
            ['album.equipment', 'Equipment', 'album', 'Equipment section title'],
            ['album.more_from', 'More from {category}', 'album', 'Related albums section title'],
            ['album.tags_count', '{count} tags', 'album', 'Tags count plural'],
            ['album.tag_count', '1 tag', 'album', 'Tags count singular'],

            // NSFW Content
            ['album.nsfw_label', 'NSFW Content', 'album', 'NSFW checkbox label in admin'],
            ['album.nsfw_help', 'Mark if album contains adult or sensitive content', 'album', 'NSFW help text in admin'],
            ['album.nsfw_title', 'Adult Content Warning', 'album', 'NSFW modal title'],
            ['album.nsfw_message', 'This gallery contains content that may not be suitable for all audiences. You must be 18 years or older to view this content.', 'album', 'NSFW modal message'],
            ['album.nsfw_album_message', 'This gallery contains adult content that may not be suitable for all viewers. You must be 18 years or older to view this content.', 'album', 'NSFW album page message'],
            ['album.nsfw_confirm', 'I am 18 or older', 'album', 'NSFW confirm button'],
            ['album.nsfw_cancel', 'Go back', 'album', 'NSFW cancel button'],
            ['album.nsfw_go_back', 'Go back', 'album', 'NSFW go back link'],
            ['album.nsfw_remember', 'Your preference will be remembered for future visits.', 'album', 'NSFW remember preference text'],
            ['album.nsfw_warning_short', '18+', 'album', 'NSFW short warning badge'],
            ['album.nsfw_password_notice', 'This gallery contains adult content. You must be 18 or older to access it.', 'album', 'NSFW notice on password page'],
            ['album.nsfw_checkbox', 'I confirm I am 18 years or older and wish to view this content', 'album', 'NSFW age confirmation checkbox'],

            // Pagination
            ['pagination.previous', 'Previous', 'pagination', 'Previous page button'],
            ['pagination.next', 'Next', 'pagination', 'Next page button'],
            ['pagination.first', 'First', 'pagination', 'First page button'],
            ['pagination.last', 'Last', 'pagination', 'Last page button'],
            ['pagination.page', 'Page', 'pagination', 'Page label'],
            ['pagination.of', 'of', 'pagination', 'Page X of Y separator'],
            ['pagination.showing', 'Showing {from} to {to} of {total}', 'pagination', 'Showing range text'],

            // Dates
            ['date.january', 'January', 'date', 'Month name'],
            ['date.february', 'February', 'date', 'Month name'],
            ['date.march', 'March', 'date', 'Month name'],
            ['date.april', 'April', 'date', 'Month name'],
            ['date.may', 'May', 'date', 'Month name'],
            ['date.june', 'June', 'date', 'Month name'],
            ['date.july', 'July', 'date', 'Month name'],
            ['date.august', 'August', 'date', 'Month name'],
            ['date.september', 'September', 'date', 'Month name'],
            ['date.october', 'October', 'date', 'Month name'],
            ['date.november', 'November', 'date', 'Month name'],
            ['date.december', 'December', 'date', 'Month name'],
            ['date.today', 'Today', 'date', 'Today label'],
            ['date.yesterday', 'Yesterday', 'date', 'Yesterday label'],
            ['date.days_ago', '{count} days ago', 'date', 'Days ago with parameter'],

            // Footer
            ['footer.copyright', '© {year} All rights reserved', 'footer', 'Copyright text with year parameter'],
            ['footer.powered_by', 'Powered by Cimaise', 'footer', 'Powered by text'],
            ['footer.privacy', 'Privacy Policy', 'footer', 'Privacy policy link'],
            ['footer.terms', 'Terms of Service', 'footer', 'Terms link'],

            // Cookie Consent
            ['cookie.title', 'Cookie Settings', 'cookie', 'Cookie banner title'],
            ['cookie.description', 'We use cookies to improve your experience. You can choose which cookies to accept.', 'cookie', 'Cookie banner description'],
            ['cookie.essential', 'Essential', 'cookie', 'Essential cookies category'],
            ['cookie.essential_desc', 'Required for the website to function', 'cookie', 'Essential cookies description'],
            ['cookie.analytics', 'Analytics', 'cookie', 'Analytics cookies category'],
            ['cookie.analytics_desc', 'Help us understand how you use the site', 'cookie', 'Analytics cookies description'],
            ['cookie.marketing', 'Marketing', 'cookie', 'Marketing cookies category'],
            ['cookie.marketing_desc', 'Personalized ads and content', 'cookie', 'Marketing cookies description'],
            ['cookie.accept', 'Accept Selected', 'cookie', 'Accept cookies button'],
            ['cookie.reject', 'Reject All', 'cookie', 'Reject cookies button'],
            ['cookie.bar_text', 'We use cookies to enhance your experience.', 'cookie', 'Cookie bar text'],
            ['cookie.manage', 'Manage cookie preferences', 'cookie', 'Manage cookies button tooltip'],

            // Lightbox
            ['lightbox.close', 'Close', 'lightbox', 'Close lightbox button'],
            ['lightbox.previous', 'Previous', 'lightbox', 'Previous image button'],
            ['lightbox.next', 'Next', 'lightbox', 'Next image button'],
            ['lightbox.zoom_in', 'Zoom In', 'lightbox', 'Zoom in button'],
            ['lightbox.zoom_out', 'Zoom Out', 'lightbox', 'Zoom out button'],
            ['lightbox.fullscreen', 'Fullscreen', 'lightbox', 'Fullscreen button'],
            ['lightbox.exit_fullscreen', 'Exit Fullscreen', 'lightbox', 'Exit fullscreen button'],
            ['lightbox.download', 'Download', 'lightbox', 'Download button'],
            ['lightbox.slideshow', 'Slideshow', 'lightbox', 'Slideshow button'],
            ['lightbox.stop_slideshow', 'Stop Slideshow', 'lightbox', 'Stop slideshow button'],
            ['lightbox.image_count', 'Image {current} of {total}', 'lightbox', 'Image counter with parameters'],

            // Social Share
            ['share.facebook', 'Share on Facebook', 'share', 'Facebook share button'],
            ['share.twitter', 'Share on Twitter', 'share', 'Twitter share button'],
            ['share.pinterest', 'Pin on Pinterest', 'share', 'Pinterest share button'],
            ['share.linkedin', 'Share on LinkedIn', 'share', 'LinkedIn share button'],
            ['share.email', 'Share via Email', 'share', 'Email share button'],
            ['share.copy_link', 'Copy Link', 'share', 'Copy link button'],
            ['share.link_copied', 'Link copied!', 'share', 'Link copied confirmation'],

            // Search
            ['search.placeholder', 'Search...', 'search', 'Search input placeholder'],
            ['search.button', 'Search', 'search', 'Search button text'],
            ['search.no_results', 'No results found for "{query}"', 'search', 'No search results message'],
            ['search.results_for', 'Results for "{query}"', 'search', 'Search results title'],
            ['search.clear', 'Clear search', 'search', 'Clear search button'],

            // Errors
            ['error.404', 'Page not found', 'error', '404 error title'],
            ['error.404_message', 'The page you are looking for does not exist.', 'error', '404 error message'],
            ['error.500', 'Server error', 'error', '500 error title'],
            ['error.500_message', 'Something went wrong. Please try again later.', 'error', '500 error message'],
            ['error.generic', 'An error occurred', 'error', 'Generic error title'],
            ['error.go_home', 'Go to Homepage', 'error', 'Go home button'],

            // Contact Form
            ['contact.title', 'Contact', 'contact', 'Contact form title'],
            ['contact.name', 'Name', 'contact', 'Name field label'],
            ['contact.email', 'Email', 'contact', 'Email field label'],
            ['contact.subject', 'Subject', 'contact', 'Subject field label'],
            ['contact.message', 'Message', 'contact', 'Message field label'],
            ['contact.send', 'Send Message', 'contact', 'Submit button text'],
            ['contact.sending', 'Sending...', 'contact', 'Sending state text'],
            ['contact.success', 'Message sent successfully!', 'contact', 'Success message'],
            ['contact.error', 'Failed to send message. Please try again.', 'contact', 'Error message'],
            ['contact.required', 'This field is required', 'contact', 'Required field error'],
            ['contact.invalid_email', 'Please enter a valid email address', 'contact', 'Invalid email error'],
            ['contact.intro', 'For collaborations and commissions, contact me via email or social.', 'contact', 'Contact page intro text'],

            // Meta/SEO
            ['meta.home_title', 'Photography Portfolio', 'meta', 'Home page title'],
            ['meta.home_description', 'Professional photography portfolio showcasing creative work', 'meta', 'Home page meta description'],
            ['meta.gallery_title', '{name} - Gallery', 'meta', 'Gallery page title with parameter'],

            // General
            ['general.loading', 'Loading...', 'general', 'Loading indicator text'],
            ['general.load_more', 'Load More', 'general', 'Load more button'],
            ['general.show_less', 'Show Less', 'general', 'Show less button'],
            ['general.read_more', 'Read More', 'general', 'Read more link'],
            ['general.see_all', 'See All', 'general', 'See all link'],
            ['general.yes', 'Yes', 'general', 'Yes confirmation'],
            ['general.no', 'No', 'general', 'No confirmation'],
            ['general.ok', 'OK', 'general', 'OK button'],
            ['general.cancel', 'Cancel', 'general', 'Cancel button'],
            ['general.save', 'Save', 'general', 'Save button'],
            ['general.delete', 'Delete', 'general', 'Delete button'],
            ['general.edit', 'Edit', 'general', 'Edit button'],
            ['general.submit', 'Submit', 'general', 'Submit button'],
            ['general.reset', 'Reset', 'general', 'Reset button'],
            ['general.view', 'View', 'general', 'View button'],
            ['general.more_count', '+{count} more', 'general', 'More items count'],

            // Galleries Page
            ['galleries.title', 'All Galleries', 'galleries', 'Galleries page title'],
            ['galleries.subtitle', 'Explore our complete collection of photography galleries with advanced filtering options', 'galleries', 'Galleries page subtitle'],

            // Image
            ['image.download', 'Download', 'image', 'Download image button'],

            // Installer
            ['installer.site_language', 'Site Language', 'installer', 'Installer site language label'],
            ['installer.site_language_help', 'Default language for the frontend', 'installer', 'Installer site language help'],
            ['installer.date_format', 'Date Format', 'installer', 'Installer date format label'],
            ['installer.date_format_help', 'Format used for displaying dates', 'installer', 'Installer date format help'],
            ['installer.date_format_iso', 'ISO', 'installer', 'ISO date format option'],
            ['installer.date_format_eu', 'European', 'installer', 'European date format option'],
            ['installer.english', 'English', 'installer', 'English language option'],
            ['installer.italian', 'Italiano', 'installer', 'Italian language option'],

            // Settings
            ['settings.date_format', 'Date Format', 'settings', 'Settings date format label'],
            ['settings.date_format_iso', '2025-01-15 (ISO)', 'settings', 'ISO date format example'],
            ['settings.date_format_european', '15-01-2025 (European)', 'settings', 'European date format example'],
            ['settings.date_format_help', 'Format used for displaying dates throughout the site', 'settings', 'Date format help text'],
            ['settings.site_language', 'Site Language', 'settings', 'Settings site language label'],
            ['settings.language_en', 'English', 'settings', 'English language option'],
            ['settings.language_it', 'Italiano', 'settings', 'Italian language option'],
            ['settings.site_language_help', 'Language used for frontend UI elements', 'settings', 'Site language help text'],

            // Cookie Banner
            ['cookie.banner_description', 'This website uses cookies to enhance your browsing experience.', 'cookie', 'Cookie banner main description'],
            ['cookie.accept_all', 'Accept all', 'cookie', 'Accept all cookies button'],
            ['cookie.reject_non_essential', 'Reject non-essential', 'cookie', 'Reject non-essential cookies button'],
            ['cookie.preferences', 'Preferences', 'cookie', 'Cookie preferences button'],
            ['cookie.save_selected', 'Save selected', 'cookie', 'Save selected cookies button'],
            ['cookie.preferences_title', 'Cookie Preferences', 'cookie', 'Cookie preferences modal title'],
            ['cookie.preferences_description', 'Manage your cookie preferences. Essential cookies are required for the site to function.', 'cookie', 'Cookie preferences description'],
            ['cookie.essential_name', 'Essential Cookies', 'cookie', 'Essential cookies category name'],
            ['cookie.essential_description', 'Required for the site to function. Cannot be disabled.', 'cookie', 'Essential cookies description'],
            ['cookie.analytics_name', 'Analytics Cookies', 'cookie', 'Analytics cookies category name'],
            ['cookie.analytics_description', 'Help us understand how you use the site to improve your experience.', 'cookie', 'Analytics cookies description'],
            ['cookie.marketing_name', 'Marketing Cookies', 'cookie', 'Marketing cookies category name'],
            ['cookie.marketing_description', 'Used to show relevant ads based on your interests.', 'cookie', 'Marketing cookies description'],
            ['cookie.privacy_statement_label', 'More information about cookies', 'cookie', 'Privacy statement link label in cookie modal'],
        ];
    }
}
