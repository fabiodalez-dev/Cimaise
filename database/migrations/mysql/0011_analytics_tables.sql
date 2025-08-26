-- Analytics system tables for MySQL
-- photoCMS Analytics Migration

-- Analytics sessions table
CREATE TABLE IF NOT EXISTS `analytics_sessions` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `session_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
    `ip_hash` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
    `user_agent` text COLLATE utf8mb4_unicode_ci,
    `browser` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `browser_version` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `platform` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `device_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `screen_resolution` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `country_code` varchar(2) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `region` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `city` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `referrer_domain` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `referrer_url` text COLLATE utf8mb4_unicode_ci,
    `landing_page` text COLLATE utf8mb4_unicode_ci,
    `started_at` datetime DEFAULT CURRENT_TIMESTAMP,
    `last_activity` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `page_views` int(11) DEFAULT 0,
    `duration` int(11) DEFAULT 0,
    `is_bot` tinyint(1) DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `session_id` (`session_id`),
    KEY `idx_analytics_sessions_started_at` (`started_at`),
    KEY `idx_analytics_sessions_country` (`country_code`),
    KEY `idx_analytics_sessions_device` (`device_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Analytics page views table
CREATE TABLE IF NOT EXISTS `analytics_pageviews` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `session_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
    `page_url` text COLLATE utf8mb4_unicode_ci NOT NULL,
    `page_title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `page_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `album_id` int(11) DEFAULT NULL,
    `category_id` int(11) DEFAULT NULL,
    `tag_id` int(11) DEFAULT NULL,
    `load_time` int(11) DEFAULT NULL,
    `viewport_width` int(11) DEFAULT NULL,
    `viewport_height` int(11) DEFAULT NULL,
    `scroll_depth` int(11) DEFAULT 0,
    `time_on_page` int(11) DEFAULT 0,
    `viewed_at` datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_analytics_pageviews_session_id` (`session_id`),
    KEY `idx_analytics_pageviews_viewed_at` (`viewed_at`),
    KEY `idx_analytics_pageviews_page_type` (`page_type`),
    KEY `idx_analytics_pageviews_album_id` (`album_id`),
    FOREIGN KEY (`session_id`) REFERENCES `analytics_sessions`(`session_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Analytics events table (downloads, searches, etc.)
CREATE TABLE IF NOT EXISTS `analytics_events` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `session_id` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
    `event_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
    `event_category` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `event_action` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `event_label` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
    `event_value` int(11) DEFAULT NULL,
    `page_url` text COLLATE utf8mb4_unicode_ci,
    `album_id` int(11) DEFAULT NULL,
    `image_id` int(11) DEFAULT NULL,
    `occurred_at` datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_analytics_events_session_id` (`session_id`),
    KEY `idx_analytics_events_type` (`event_type`),
    KEY `idx_analytics_events_occurred_at` (`occurred_at`),
    FOREIGN KEY (`session_id`) REFERENCES `analytics_sessions`(`session_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Analytics daily summaries (pre-computed stats)
CREATE TABLE IF NOT EXISTS `analytics_daily_summary` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `date` date NOT NULL,
    `total_sessions` int(11) DEFAULT 0,
    `total_pageviews` int(11) DEFAULT 0,
    `unique_visitors` int(11) DEFAULT 0,
    `bounce_rate` decimal(5,2) DEFAULT 0.00,
    `avg_session_duration` int(11) DEFAULT 0,
    `top_pages` text COLLATE utf8mb4_unicode_ci,
    `top_countries` text COLLATE utf8mb4_unicode_ci,
    `top_browsers` text COLLATE utf8mb4_unicode_ci,
    `top_albums` text COLLATE utf8mb4_unicode_ci,
    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `date` (`date`),
    KEY `idx_analytics_daily_summary_date` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Analytics settings table
CREATE TABLE IF NOT EXISTS `analytics_settings` (
    `id` int(11) NOT NULL AUTO_INCREMENT,
    `setting_key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
    `setting_value` text COLLATE utf8mb4_unicode_ci,
    `description` text COLLATE utf8mb4_unicode_ci,
    `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default analytics settings
INSERT IGNORE INTO `analytics_settings` (`setting_key`, `setting_value`, `description`) VALUES
('analytics_enabled', 'true', 'Enable/disable analytics tracking'),
('ip_anonymization', 'true', 'Anonymize IP addresses for privacy'),
('data_retention_days', '365', 'Number of days to keep detailed analytics data'),
('real_time_enabled', 'true', 'Enable real-time visitor tracking'),
('geolocation_enabled', 'true', 'Enable geographic data collection'),
('bot_detection_enabled', 'true', 'Filter out bot traffic'),
('session_timeout_minutes', '30', 'Session timeout in minutes'),
('export_enabled', 'true', 'Allow data export functionality');