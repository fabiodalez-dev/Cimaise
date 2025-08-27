SELECT 'ANALYTICS TABLES IN TEMPLATE DATABASE:' as info;
SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'analytics%' ORDER BY name;