-- Check analytics tables in template database
SELECT 'Analytics Tables Found:' as message;
SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'analytics%' ORDER BY name;

SELECT '';
SELECT 'Total Analytics Tables Count:' as message;
SELECT COUNT(*) as count FROM sqlite_master WHERE type='table' AND name LIKE 'analytics%';

SELECT '';
SELECT 'Analytics Settings:' as message;
SELECT * FROM analytics_settings LIMIT 3;