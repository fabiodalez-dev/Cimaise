-- Enhance users table for multi-user system with roles and additional fields (MySQL)
-- Separate statements for duplicate column handling

ALTER TABLE users ADD COLUMN first_name VARCHAR(100) NULL AFTER email;
ALTER TABLE users ADD COLUMN last_name VARCHAR(100) NULL AFTER first_name;
ALTER TABLE users ADD COLUMN is_active TINYINT(1) DEFAULT 1 AFTER role;
ALTER TABLE users ADD COLUMN last_login DATETIME NULL AFTER is_active;
ALTER TABLE users ADD COLUMN updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

-- Update role column to support both admin and user roles
ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'user') DEFAULT 'user';
