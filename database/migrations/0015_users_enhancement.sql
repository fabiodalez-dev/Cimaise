-- Enhance users table for multi-user system with roles and additional fields
ALTER TABLE users 
  ADD COLUMN IF NOT EXISTS first_name VARCHAR(100) NULL AFTER email,
  ADD COLUMN IF NOT EXISTS last_name VARCHAR(100) NULL AFTER first_name,
  ADD COLUMN IF NOT EXISTS is_active BOOLEAN DEFAULT TRUE AFTER role,
  ADD COLUMN IF NOT EXISTS last_login DATETIME NULL AFTER is_active,
  ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

-- Update role column to support both admin and user roles
ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'user') DEFAULT 'user';