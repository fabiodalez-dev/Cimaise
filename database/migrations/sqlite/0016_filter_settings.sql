-- Migration: 0016_filter_settings.sql (SQLite version)
-- Create filter settings table for galleries page configuration

CREATE TABLE IF NOT EXISTS filter_settings (
    setting_key TEXT PRIMARY KEY,
    setting_value TEXT NOT NULL,
    description TEXT,
    sort_order INTEGER DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Insert default filter settings
INSERT OR REPLACE INTO filter_settings (setting_key, setting_value, description, sort_order) VALUES
('enabled', '1', 'Enable/disable filter functionality', 1),
('show_categories', '1', 'Show categories filter', 2),
('show_tags', '1', 'Show tags filter', 3),
('show_cameras', '1', 'Show cameras filter', 4),
('show_lenses', '1', 'Show lenses filter', 5),
('show_films', '1', 'Show films filter', 6),
('show_developers', '0', 'Show developers filter', 7),
('show_labs', '0', 'Show labs filter', 8),
('show_locations', '1', 'Show locations filter', 9),
('show_year', '1', 'Show year filter', 10),
('grid_columns_desktop', '3', 'Grid columns on desktop', 11),
('grid_columns_tablet', '2', 'Grid columns on tablet', 12),
('grid_columns_mobile', '1', 'Grid columns on mobile', 13),
('grid_gap', 'normal', 'Grid gap size (small, normal, large)', 14),
('animation_enabled', '1', 'Enable GSAP animations', 15),
('animation_duration', '0.6', 'Animation duration in seconds', 16);

-- Create trigger to update updated_at timestamp
CREATE TRIGGER IF NOT EXISTS filter_settings_updated_at 
    AFTER UPDATE ON filter_settings
BEGIN
    UPDATE filter_settings SET updated_at = CURRENT_TIMESTAMP WHERE setting_key = NEW.setting_key;
END;