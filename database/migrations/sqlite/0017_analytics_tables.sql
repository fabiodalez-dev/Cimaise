-- Analytics system tables
-- photoCMS Analytics Migration (SQLite version)

-- Analytics sessions table
CREATE TABLE IF NOT EXISTS analytics_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
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
    started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_activity DATETIME DEFAULT CURRENT_TIMESTAMP,
    page_views INTEGER DEFAULT 0,
    duration INTEGER DEFAULT 0,
    is_bot BOOLEAN DEFAULT 0
);

-- Analytics page views table
CREATE TABLE IF NOT EXISTS analytics_pageviews (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id VARCHAR(64) NOT NULL,
    page_url TEXT NOT NULL,
    page_title VARCHAR(255),
    page_type VARCHAR(50),
    album_id INTEGER NULL,
    category_id INTEGER NULL,
    tag_id INTEGER NULL,
    load_time INTEGER,
    viewport_width INTEGER,
    viewport_height INTEGER,
    scroll_depth INTEGER DEFAULT 0,
    time_on_page INTEGER DEFAULT 0,
    viewed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES analytics_sessions(session_id) ON DELETE CASCADE
);

-- Analytics events table (downloads, searches, etc.)
CREATE TABLE IF NOT EXISTS analytics_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id VARCHAR(64) NOT NULL,
    event_type VARCHAR(50) NOT NULL,
    event_category VARCHAR(100),
    event_action VARCHAR(100),
    event_label VARCHAR(255),
    event_value INTEGER,
    page_url TEXT,
    album_id INTEGER NULL,
    image_id INTEGER NULL,
    occurred_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES analytics_sessions(session_id) ON DELETE CASCADE
);

-- Analytics daily summaries (pre-computed stats)
CREATE TABLE IF NOT EXISTS analytics_daily_summary (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    date DATE NOT NULL UNIQUE,
    total_sessions INTEGER DEFAULT 0,
    total_pageviews INTEGER DEFAULT 0,
    unique_visitors INTEGER DEFAULT 0,
    bounce_rate DECIMAL(5,2) DEFAULT 0,
    avg_session_duration INTEGER DEFAULT 0,
    top_pages TEXT, -- JSON array
    top_countries TEXT, -- JSON array
    top_browsers TEXT, -- JSON array
    top_albums TEXT, -- JSON array
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Analytics settings table
CREATE TABLE IF NOT EXISTS analytics_settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    setting_key VARCHAR(255) NOT NULL UNIQUE,
    setting_value TEXT,
    description TEXT,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Create indexes for performance
CREATE INDEX IF NOT EXISTS idx_analytics_sessions_session_id ON analytics_sessions(session_id);
CREATE INDEX IF NOT EXISTS idx_analytics_sessions_started_at ON analytics_sessions(started_at);
CREATE INDEX IF NOT EXISTS idx_analytics_sessions_country ON analytics_sessions(country_code);
CREATE INDEX IF NOT EXISTS idx_analytics_sessions_device ON analytics_sessions(device_type);

CREATE INDEX IF NOT EXISTS idx_analytics_pageviews_session_id ON analytics_pageviews(session_id);
CREATE INDEX IF NOT EXISTS idx_analytics_pageviews_viewed_at ON analytics_pageviews(viewed_at);
CREATE INDEX IF NOT EXISTS idx_analytics_pageviews_page_type ON analytics_pageviews(page_type);
CREATE INDEX IF NOT EXISTS idx_analytics_pageviews_album_id ON analytics_pageviews(album_id);

CREATE INDEX IF NOT EXISTS idx_analytics_events_session_id ON analytics_events(session_id);
CREATE INDEX IF NOT EXISTS idx_analytics_events_type ON analytics_events(event_type);
CREATE INDEX IF NOT EXISTS idx_analytics_events_occurred_at ON analytics_events(occurred_at);

CREATE INDEX IF NOT EXISTS idx_analytics_daily_summary_date ON analytics_daily_summary(date);

-- Insert default analytics settings
INSERT OR IGNORE INTO analytics_settings (setting_key, setting_value, description) VALUES
('analytics_enabled', 'true', 'Enable/disable analytics tracking'),
('ip_anonymization', 'true', 'Anonymize IP addresses for privacy'),
('data_retention_days', '365', 'Number of days to keep detailed analytics data'),
('real_time_enabled', 'true', 'Enable real-time visitor tracking'),
('geolocation_enabled', 'true', 'Enable geographic data collection'),
('bot_detection_enabled', 'true', 'Filter out bot traffic'),
('session_timeout_minutes', '30', 'Session timeout in minutes'),
('export_enabled', 'true', 'Allow data export functionality');