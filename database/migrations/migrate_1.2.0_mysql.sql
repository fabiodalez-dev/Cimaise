-- Migration: Analytics Pro IP hashing + ratings multi-vote support (MySQL)
-- Run: php bin/console db:migrate

-- Analytics Pro: add ip_hash and drop ip_address
ALTER TABLE analytics_pro_sessions
  ADD COLUMN ip_hash CHAR(64) NULL AFTER user_id;

UPDATE analytics_pro_sessions
  SET ip_hash = SHA2(CONCAT(ip_address, 'cimaise_salt'), 256)
  WHERE ip_address IS NOT NULL AND ip_hash IS NULL;

ALTER TABLE analytics_pro_sessions
  DROP COLUMN ip_address;

ALTER TABLE analytics_pro_events
  ADD COLUMN ip_hash CHAR(64) NULL AFTER session_id;

UPDATE analytics_pro_events
  SET ip_hash = SHA2(CONCAT(ip_address, 'cimaise_salt'), 256)
  WHERE ip_address IS NOT NULL AND ip_hash IS NULL;

ALTER TABLE analytics_pro_events
  DROP COLUMN ip_address;

-- Image ratings: allow multiple votes per image/user
ALTER TABLE plugin_image_ratings
  DROP PRIMARY KEY;

ALTER TABLE plugin_image_ratings
  ADD COLUMN id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST;

ALTER TABLE plugin_image_ratings
  ADD UNIQUE KEY uniq_plugin_image_ratings_image_rated_by (image_id, rated_by);

ALTER TABLE plugin_image_ratings
  ADD KEY idx_plugin_image_ratings_image_id (image_id);
