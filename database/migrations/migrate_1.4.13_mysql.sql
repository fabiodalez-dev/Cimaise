-- Migration 1.4.13 (MySQL) — allow 'jxl' in image_variants.format (#109)
--
-- JPEG-XL variants are emitted by the libvips engine when the build supports
-- it. The ENUM previously forbade 'jxl', so the INSERT/REPLACE silently failed.
-- Single ALTER, no inner semicolons (migration runner splits on ';').

ALTER TABLE image_variants MODIFY COLUMN format ENUM('avif','webp','jpg','jxl') NOT NULL;
