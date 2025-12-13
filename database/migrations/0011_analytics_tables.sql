-- Analytics system tables
-- photoCMS Analytics Migration (MySQL version)

-- Analytics sessions table
CREATE TABLE IF NOT EXISTS analytics_sessions (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    session_id VARCHAR(64) NOT NULL UNIQUE,
    ip_hash VARCHAR(64) NOT NULL,
    user_agent TEXT,
    browser VARCHAR(100),
    browser_version VARCHAR(50),
    platform VARCHAR(100),
    device_type VARCHAR(50),
    screen_resolution VARCHAR(20),
    country_code VARCHAR(2),
    region VARCHAR(100),
    city VARCHAR(100),
    referrer_domain VARCHAR(255),
    referrer_url TEXT,
    landing_page TEXT,
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    page_views INT DEFAULT 0,
    duration INT DEFAULT 0,
    is_bot TINYINT(1) DEFAULT 0,
    INDEX idx_session_id (session_id),
    INDEX idx_started_at (started_at),
    INDEX idx_country (country_code),
    INDEX idx_device (device_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Analytics page views table
CREATE TABLE IF NOT EXISTS analytics_pageviews (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    session_id VARCHAR(64) NOT NULL,
    page_url TEXT NOT NULL,
    page_title VARCHAR(255),
    page_type VARCHAR(50),
    album_id BIGINT UNSIGNED NULL,
    category_id BIGINT UNSIGNED NULL,
    tag_id BIGINT UNSIGNED NULL,
    load_time INT,
    viewport_width INT,
    viewport_height INT,
    scroll_depth INT DEFAULT 0,
    time_on_page INT DEFAULT 0,
    viewed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES analytics_sessions(session_id) ON DELETE CASCADE,
    INDEX idx_session_id (session_id),
    INDEX idx_viewed_at (viewed_at),
    INDEX idx_page_type (page_type),
    INDEX idx_album_id (album_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Analytics events table (downloads, searches, etc.)
CREATE TABLE IF NOT EXISTS analytics_events (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    session_id VARCHAR(64) NOT NULL,
    event_type VARCHAR(50) NOT NULL,
    event_category VARCHAR(100),
    event_action VARCHAR(100),
    event_label VARCHAR(255),
    event_value INT,
    page_url TEXT,
    album_id BIGINT UNSIGNED NULL,
    image_id BIGINT UNSIGNED NULL,
    occurred_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES analytics_sessions(session_id) ON DELETE CASCADE,
    INDEX idx_session_id (session_id),
    INDEX idx_event_type (event_type),
    INDEX idx_occurred_at (occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Analytics daily summaries (pre-computed stats)
CREATE TABLE IF NOT EXISTS analytics_daily_summary (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    date DATE NOT NULL UNIQUE,
    total_sessions INT DEFAULT 0,
    total_pageviews INT DEFAULT 0,
    unique_visitors INT DEFAULT 0,
    bounce_rate DECIMAL(5,2) DEFAULT 0,
    avg_session_duration INT DEFAULT 0,
    top_pages JSON, -- JSON array
    top_countries JSON, -- JSON array
    top_browsers JSON, -- JSON array
    top_albums JSON, -- JSON array
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_date (date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Analytics settings table
CREATE TABLE IF NOT EXISTS analytics_settings (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    setting_key VARCHAR(255) NOT NULL UNIQUE,
    setting_value TEXT,
    description TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default analytics settings
INSERT IGNORE INTO analytics_settings (setting_key, setting_value, description) VALUES
('analytics_enabled', 'true', 'Enable/disable analytics tracking'),
('ip_anonymization', 'true', 'Anonymize IP addresses for privacy'),
('data_retention_days', '365', 'Number of days to keep detailed analytics data'),
('real_time_enabled', 'true', 'Enable real-time visitor tracking'),
('geolocation_enabled', 'true', 'Enable geographic data collection'),
('bot_detection_enabled', 'true', 'Filter out bot traffic'),
('session_timeout_minutes', '30', 'Session timeout in minutes'),
('export_enabled', 'true', 'Allow data export functionality');
