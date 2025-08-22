-- Local bootstrap for photoCMS database and user
CREATE DATABASE IF NOT EXISTS `photocms`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_0900_ai_ci;

CREATE USER IF NOT EXISTS 'photocms'@'localhost' IDENTIFIED BY 'phcms_P4fJt7Vx9Lq2Bz6Dg8Rm3H';
GRANT ALL PRIVILEGES ON `photocms`.* TO 'photocms'@'localhost';
FLUSH PRIVILEGES;

