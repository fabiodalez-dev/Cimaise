-- ============================================
-- Cimaise - Template IDs Migration (MySQL)
-- Migrates template IDs from 7-12 to 1-6
-- Magazine Split becomes ID 3
-- ============================================

-- Start transaction for safety
START TRANSACTION;

-- Disable foreign key checks temporarily
SET FOREIGN_KEY_CHECKS = 0;

-- Step 1: Update albums to use new template IDs (7->1, 8->2, 9->3, etc.)
UPDATE `albums` SET `template_id` = `template_id` - 6 WHERE `template_id` BETWEEN 7 AND 12;

-- Step 2: Update templates table with new IDs
UPDATE `templates` SET `id` = `id` - 6 WHERE `id` BETWEEN 7 AND 12;

-- Step 3: Update default_template_id setting
UPDATE `settings` SET `value` = '1' WHERE `key` = 'gallery.default_template_id' AND `value` IN ('7', '9');

-- Step 4: Reset AUTO_INCREMENT
ALTER TABLE `templates` AUTO_INCREMENT = 7;

-- Re-enable foreign key checks
SET FOREIGN_KEY_CHECKS = 1;

COMMIT;

-- Verification query (run manually to confirm)
-- SELECT id, name, slug FROM templates ORDER BY id;
-- Expected: 1=Grid Classica, 2=Masonry Portfolio, 3=Magazine Split, 4=Gallery Fullscreen, 5=Grid Compatta, 6=Grid Ampia
