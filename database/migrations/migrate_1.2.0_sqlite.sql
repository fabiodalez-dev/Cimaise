-- Migration: Analytics Pro IP hashing + ratings multi-vote support (SQLite)
-- Run: php bin/console db:migrate

PRAGMA foreign_keys = OFF;
BEGIN TRANSACTION;

-- Image ratings: allow multiple votes per image/user
CREATE TABLE plugin_image_ratings_new (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  image_id INTEGER NOT NULL,
  rating INTEGER NOT NULL CHECK(rating >= 0 AND rating <= 5),
  rated_at TEXT DEFAULT CURRENT_TIMESTAMP,
  rated_by INTEGER NULL,
  UNIQUE(image_id, rated_by),
  FOREIGN KEY (image_id) REFERENCES images(id) ON DELETE CASCADE,
  FOREIGN KEY (rated_by) REFERENCES users(id) ON DELETE SET NULL
);

INSERT INTO plugin_image_ratings_new (image_id, rating, rated_at, rated_by)
  SELECT image_id, rating, rated_at, rated_by FROM plugin_image_ratings;

DROP TABLE plugin_image_ratings;
ALTER TABLE plugin_image_ratings_new RENAME TO plugin_image_ratings;

CREATE INDEX IF NOT EXISTS idx_plugin_image_ratings_image_id ON plugin_image_ratings(image_id);
CREATE INDEX IF NOT EXISTS idx_plugin_image_ratings_rated_by ON plugin_image_ratings(rated_by);

-- Analytics Pro: add ip_hash and drop ip_address via table rebuild
CREATE TABLE analytics_pro_sessions_new (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  session_id TEXT NOT NULL UNIQUE,
  user_id INTEGER,
  ip_hash TEXT,
  user_agent TEXT,
  device_type TEXT,
  browser TEXT,
  country TEXT,
  started_at TEXT DEFAULT CURRENT_TIMESTAMP,
  last_activity TEXT DEFAULT CURRENT_TIMESTAMP,
  ended_at TEXT,
  duration INTEGER DEFAULT 0,
  pageviews INTEGER DEFAULT 0,
  events_count INTEGER DEFAULT 0,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

INSERT INTO analytics_pro_sessions_new (
  id, session_id, user_id, ip_hash, user_agent, device_type, browser, country,
  started_at, last_activity, ended_at, duration, pageviews, events_count
)
  SELECT id, session_id, user_id, NULL, user_agent, device_type, browser, country,
         started_at, last_activity, ended_at, duration, pageviews, events_count
    FROM analytics_pro_sessions;

CREATE TABLE analytics_pro_events_new (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  event_name TEXT NOT NULL,
  category TEXT,
  action TEXT,
  label TEXT,
  value INTEGER,
  user_id INTEGER,
  session_id TEXT,
  ip_hash TEXT,
  user_agent TEXT,
  referrer TEXT,
  device_type TEXT,
  browser TEXT,
  country TEXT,
  metadata TEXT,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  FOREIGN KEY (session_id) REFERENCES analytics_pro_sessions(session_id) ON DELETE SET NULL
);

INSERT INTO analytics_pro_events_new (
  id, event_name, category, action, label, value, user_id, session_id, ip_hash,
  user_agent, referrer, device_type, browser, country, metadata, created_at
)
  SELECT id, event_name, category, action, label, value, user_id, session_id, NULL,
         user_agent, referrer, device_type, browser, country, metadata, created_at
    FROM analytics_pro_events;

DROP TABLE analytics_pro_events;
DROP TABLE analytics_pro_sessions;
ALTER TABLE analytics_pro_sessions_new RENAME TO analytics_pro_sessions;
ALTER TABLE analytics_pro_events_new RENAME TO analytics_pro_events;

CREATE INDEX IF NOT EXISTS idx_analytics_pro_sessions_user_id ON analytics_pro_sessions(user_id);
CREATE INDEX IF NOT EXISTS idx_analytics_pro_sessions_started_at ON analytics_pro_sessions(started_at);
CREATE INDEX IF NOT EXISTS idx_analytics_pro_event_name ON analytics_pro_events(event_name);
CREATE INDEX IF NOT EXISTS idx_analytics_pro_category ON analytics_pro_events(category);
CREATE INDEX IF NOT EXISTS idx_analytics_pro_created_at ON analytics_pro_events(created_at);
CREATE INDEX IF NOT EXISTS idx_analytics_pro_user_id ON analytics_pro_events(user_id);
CREATE INDEX IF NOT EXISTS idx_analytics_pro_session_id ON analytics_pro_events(session_id);

COMMIT;
PRAGMA foreign_keys = ON;
