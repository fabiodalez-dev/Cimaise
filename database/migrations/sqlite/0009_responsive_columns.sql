-- Update templates to support responsive columns (SQLite)
-- This migration updates existing templates to use the new responsive column format

UPDATE templates 
SET settings = json_set(
    COALESCE(settings, '{}'),
    '$.columns',
    json_object(
        'desktop', json_extract(settings, '$.columns'),
        'tablet', MAX(MIN(json_extract(settings, '$.columns'), 4), 2),
        'mobile', CASE 
            WHEN json_extract(settings, '$.columns') > 3 THEN 2
            ELSE 1
        END
    )
)
WHERE settings IS NOT NULL 
AND json_extract(settings, '$.columns') IS NOT NULL;