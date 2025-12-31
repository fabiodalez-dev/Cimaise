<?php
/**
 * Plugin Name: Image Rating
 * Description: Add star rating system to images (1-5 stars) with sorting and filtering
 * Version: 1.0.0
 * Author: Cimaise Team
 * License: MIT
 */

declare(strict_types=1);

use App\Support\Hooks;

require_once __DIR__ . '/src/ImageRating.php';

/**
 * Image Rating Plugin
 *
 * Features:
 * - 5-star rating system for images
 * - Filter images by rating
 * - Sort by rating
 * - Display ratings in frontend lightbox
 * - Admin bulk rating
 * - Export images by rating
 */
class ImageRatingPlugin
{
    private const PLUGIN_NAME = 'image-rating';
    private const VERSION = '1.0.0';

    private PDO $db;
    private ImageRating $ratingService;

    public function __construct()
    {
        $this->init();
    }

    public function init(): void
    {
        // Get database
        Hooks::addAction('cimaise_init', [$this, 'setup'], 10, self::PLUGIN_NAME);

        // Admin hooks
        Hooks::addFilter('admin_list_columns', [$this, 'addRatingColumn'], 10, self::PLUGIN_NAME);
        Hooks::addFilter('admin_bulk_actions', [$this, 'addBulkActions'], 10, self::PLUGIN_NAME);
        Hooks::addFilter('admin_form_fields', [$this, 'addRatingField'], 10, self::PLUGIN_NAME);

        // Frontend hooks
        Hooks::addFilter('lightbox_config', [$this, 'addRatingToLightbox'], 10, self::PLUGIN_NAME);
        Hooks::addFilter('album_view_images', [$this, 'sortByRating'], 10, self::PLUGIN_NAME);

        // Save hooks
        Hooks::addAction('image_after_upload', [$this, 'initializeRating'], 10, self::PLUGIN_NAME);

        // Settings
        Hooks::addFilter('settings_tabs', [$this, 'addSettingsTab'], 10, self::PLUGIN_NAME);

        // Assets
        Hooks::addFilter('admin_css', [$this, 'addAdminCss'], 10, self::PLUGIN_NAME);
        Hooks::addFilter('admin_js', [$this, 'addAdminJs'], 10, self::PLUGIN_NAME);

        error_log("Image Rating plugin initialized");
    }

    public function setup($db, $pluginManager): void
    {
        $this->db = $db instanceof \App\Support\Database ? $db->pdo() : $db;
        $this->ratingService = new ImageRating($this->db);
        $this->ratingService->createTable();
    }

    /**
     * Add rating column to images list in admin
     */
    public function addRatingColumn(array $columns, string $entityType): array
    {
        if ($entityType === 'image') {
            // Insert rating column after filename
            $position = array_search('filename', array_keys($columns)) + 1;

            $columns = array_slice($columns, 0, $position, true) +
                       ['rating' => ['label' => '⭐ Rating', 'sortable' => true]] +
                       array_slice($columns, $position, null, true);
        }

        return $columns;
    }

    /**
     * Add bulk rating actions
     */
    public function addBulkActions(array $actions, string $entityType): array
    {
        if ($entityType === 'image') {
            $actions['rate_5_stars'] = 'Rate 5 Stars ⭐⭐⭐⭐⭐';
            $actions['rate_4_stars'] = 'Rate 4 Stars ⭐⭐⭐⭐';
            $actions['rate_3_stars'] = 'Rate 3 Stars ⭐⭐⭐';
            $actions['rate_2_stars'] = 'Rate 2 Stars ⭐⭐';
            $actions['rate_1_star'] = 'Rate 1 Star ⭐';
            $actions['rate_unrated'] = 'Remove Rating';
        }

        return $actions;
    }

    /**
     * Add rating field to image edit form
     */
    public function addRatingField(array $fields, string $entityType, ?int $entityId): array
    {
        if ($entityType === 'image' && $entityId) {
            $currentRating = $this->ratingService->getRating($entityId);

            $fields['rating'] = [
                'type' => 'rating',
                'label' => 'Rating',
                'description' => 'Rate this image from 1 to 5 stars',
                'value' => $currentRating,
                'min' => 0,
                'max' => 5,
                'group' => 'Quality'
            ];
        }

        return $fields;
    }

    /**
     * Add rating display to lightbox
     */
    public function addRatingToLightbox(array $config): array
    {
        // Add rating info to caption
        $config['addCaptionHTMLFn'] = function($item) {
            $imageId = $item['id'] ?? null;
            $rating = $imageId ? $this->ratingService->getRating($imageId) : 0;

            if ($rating > 0) {
                $stars = str_repeat('⭐', $rating);
                return "<div class='pswp__rating'>{$stars} ({$rating}/5)</div>";
            }

            return '';
        };

        return $config;
    }

    /**
     * Sort images by rating (optional)
     */
    public function sortByRating(array $images, int $albumId): array
    {
        // Check if sort by rating is enabled in settings
        // For now, just add rating data to each image
        foreach ($images as &$image) {
            $image['rating'] = $this->ratingService->getRating($image['id']);
        }

        // Optional: Auto-sort by rating DESC
        // usort($images, fn($a, $b) => ($b['rating'] ?? 0) <=> ($a['rating'] ?? 0));

        return $images;
    }

    /**
     * Initialize rating on image upload (default 0)
     */
    public function initializeRating(int $imageId, array $imageData, string $filePath): void
    {
        $this->ratingService->setRating($imageId, 0);
        error_log("Image Rating: Initialized rating for image {$imageId}");
    }

    /**
     * Add settings tab
     */
    public function addSettingsTab(array $tabs): array
    {
        $tabs['image_rating'] = [
            'title' => 'Image Rating',
            'icon' => 'star',
            'fields' => [
                'rating_enabled' => [
                    'type' => 'checkbox',
                    'label' => 'Enable Rating System',
                    'default' => true
                ],
                'rating_show_frontend' => [
                    'type' => 'checkbox',
                    'label' => 'Show Ratings in Frontend',
                    'description' => 'Display star ratings in lightbox and galleries',
                    'default' => true
                ],
                'rating_auto_sort' => [
                    'type' => 'checkbox',
                    'label' => 'Auto-Sort by Rating',
                    'description' => 'Automatically sort images by rating (highest first)',
                    'default' => false
                ],
                'rating_filter_threshold' => [
                    'type' => 'select',
                    'label' => 'Minimum Rating for Display',
                    'description' => 'Only show images with rating >= threshold (0 = show all)',
                    'options' => [
                        '0' => 'Show All',
                        '3' => '3+ Stars',
                        '4' => '4+ Stars',
                        '5' => '5 Stars Only'
                    ],
                    'default' => '0'
                ]
            ]
        ];

        return $tabs;
    }

    /**
     * Add CSS for rating stars
     */
    public function addAdminCss(array $cssFiles): array
    {
        $cssFiles[] = plugins_url('image-rating/assets/rating.css');
        return $cssFiles;
    }

    /**
     * Add JavaScript for interactive rating
     */
    public function addAdminJs(array $jsFiles): array
    {
        $jsFiles[] = plugins_url('image-rating/assets/rating.js');
        return $jsFiles;
    }
}

// Helper function for plugin URL
if (!function_exists('plugins_url')) {
    function plugins_url(string $path): string
    {
        return '/plugins/' . ltrim($path, '/');
    }
}

// Initialize plugin
new ImageRatingPlugin();
