-- Migration: 1.1.0
-- Database: MySQL
-- Description: Add update system tables (migrations and update_logs)

-- Migrations table to track executed migrations
CREATE TABLE IF NOT EXISTS `migrations` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `version` VARCHAR(20) NOT NULL,
    `filename` VARCHAR(255) NOT NULL,
    `batch` INT NOT NULL DEFAULT 1,
    `executed_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `unique_version` (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Update logs table to track update history
CREATE TABLE IF NOT EXISTS `update_logs` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `from_version` VARCHAR(20) NOT NULL,
    `to_version` VARCHAR(20) NOT NULL,
    `status` ENUM('started','completed','failed','rolled_back') NOT NULL DEFAULT 'started',
    `backup_path` VARCHAR(500) DEFAULT NULL,
    `error_message` TEXT,
    `started_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `completed_at` DATETIME DEFAULT NULL,
    `executed_by` INT DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_update_logs_started_at` (`started_at`),
    CONSTRAINT `fk_update_logs_user` FOREIGN KEY (`executed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
