-- Update templates to support responsive columns (MySQL)
-- This migration updates existing templates to use the new responsive column format

UPDATE templates 
SET settings = JSON_SET(
    COALESCE(settings, '{}'),
    '$.columns',
    JSON_OBJECT(
        'desktop', JSON_EXTRACT(settings, '$.columns'),
        'tablet', GREATEST(LEAST(JSON_EXTRACT(settings, '$.columns'), 4), 2),
        'mobile', CASE 
            WHEN JSON_EXTRACT(settings, '$.columns') > 3 THEN 2
            ELSE 1
        END
    )
)
WHERE settings IS NOT NULL 
AND JSON_EXTRACT(settings, '$.columns') IS NOT NULL 
AND JSON_TYPE(JSON_EXTRACT(settings, '$.columns')) = 'INTEGER';