-- SEO Settings Migration - MySQL Version
-- Add SEO-related fields to albums table and global SEO settings

-- Add SEO fields to albums table (separate statements for error handling)
ALTER TABLE albums ADD COLUMN seo_title VARCHAR(255) DEFAULT NULL;
ALTER TABLE albums ADD COLUMN seo_description TEXT DEFAULT NULL;
ALTER TABLE albums ADD COLUMN seo_keywords TEXT DEFAULT NULL;
ALTER TABLE albums ADD COLUMN og_title VARCHAR(255) DEFAULT NULL;
ALTER TABLE albums ADD COLUMN og_description TEXT DEFAULT NULL;
ALTER TABLE albums ADD COLUMN og_image_path VARCHAR(500) DEFAULT NULL;
ALTER TABLE albums ADD COLUMN schema_type VARCHAR(100) DEFAULT 'ImageGallery';
ALTER TABLE albums ADD COLUMN schema_data TEXT DEFAULT NULL;
ALTER TABLE albums ADD COLUMN canonical_url VARCHAR(500) DEFAULT NULL;
ALTER TABLE albums ADD COLUMN robots_index TINYINT(1) DEFAULT 1;
ALTER TABLE albums ADD COLUMN robots_follow TINYINT(1) DEFAULT 1;

-- Create indexes for SEO fields (MySQL 8.0+)
CREATE INDEX idx_albums_seo_title ON albums(seo_title);
CREATE INDEX idx_albums_robots ON albums(robots_index, robots_follow);

-- Insert global SEO settings
INSERT IGNORE INTO settings (`key`, `value`, `type`) VALUES
-- Site-wide SEO settings
('seo.site_title', 'Photography Portfolio', 'string'),
('seo.site_description', 'Professional photography portfolio showcasing creative work and artistic vision', 'string'),
('seo.site_keywords', 'photography, portfolio, professional photographer, creative photography', 'string'),
('seo.author_name', '', 'string'),
('seo.author_url', '', 'string'),
('seo.organization_name', '', 'string'),
('seo.organization_url', '', 'string'),

-- Social media and Open Graph
('seo.og_site_name', 'Photography Portfolio', 'string'),
('seo.og_type', 'website', 'string'),
('seo.og_locale', 'en_US', 'string'),
('seo.twitter_card', 'summary_large_image', 'string'),
('seo.twitter_site', '', 'string'),
('seo.twitter_creator', '', 'string'),

-- Schema.org settings
('seo.schema_enabled', 'true', 'boolean'),
('seo.breadcrumbs_enabled', 'true', 'boolean'),
('seo.local_business_enabled', 'false', 'boolean'),
('seo.local_business_name', '', 'string'),
('seo.local_business_type', 'ProfessionalService', 'string'),
('seo.local_business_address', '', 'string'),
('seo.local_business_city', '', 'string'),
('seo.local_business_postal_code', '', 'string'),
('seo.local_business_country', '', 'string'),
('seo.local_business_phone', '', 'string'),
('seo.local_business_geo_lat', '', 'string'),
('seo.local_business_geo_lng', '', 'string'),
('seo.local_business_opening_hours', '', 'string'),

-- Professional photographer schema
('seo.photographer_job_title', 'Professional Photographer', 'string'),
('seo.photographer_services', 'Professional Photography Services', 'string'),
('seo.photographer_area_served', '', 'string'),
('seo.photographer_same_as', '', 'string'),

-- Technical SEO
('seo.robots_default', 'index,follow', 'string'),
('seo.canonical_base_url', '', 'string'),
('seo.sitemap_enabled', 'true', 'boolean'),
('seo.analytics_gtag', '', 'string'),
('seo.analytics_gtm', '', 'string'),

-- Image SEO
('seo.image_alt_auto', 'true', 'boolean'),
('seo.image_copyright_notice', '', 'string'),
('seo.image_license_url', '', 'string'),
('seo.image_acquire_license_page', '', 'string'),

-- Performance and crawling
('seo.preload_critical_images', 'true', 'boolean'),
('seo.lazy_load_images', 'true', 'boolean'),
('seo.structured_data_format', 'json-ld', 'string');
