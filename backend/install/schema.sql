-- Kaneas database schema.
-- `{prefix}` is replaced by the table prefix chosen in the installer.
-- Statements are separated by a semicolon at the end of a line.

CREATE TABLE `{prefix}settings` (
  `name` VARCHAR(64) NOT NULL,
  `value` TEXT NOT NULL,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email` VARCHAR(190) NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('user','admin') NOT NULL DEFAULT 'user',
  `locale` VARCHAR(5) NOT NULL DEFAULT 'nl',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `email_verified_at` DATETIME NULL,
  `last_login_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}refresh_tokens` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `family_id` CHAR(32) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `revoked_at` DATETIME NULL,
  `user_agent` VARCHAR(255) NULL,
  `ip` VARCHAR(45) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_refresh_token_hash` (`token_hash`),
  KEY `idx_refresh_family` (`family_id`),
  KEY `idx_refresh_user` (`user_id`),
  CONSTRAINT `fk_{prefix}refresh_user` FOREIGN KEY (`user_id`) REFERENCES `{prefix}users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}user_tokens` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `purpose` VARCHAR(32) NOT NULL,
  `token_hash` CHAR(64) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `used_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_token_hash` (`token_hash`),
  KEY `idx_user_tokens_user` (`user_id`, `purpose`),
  CONSTRAINT `fk_{prefix}user_tokens_user` FOREIGN KEY (`user_id`) REFERENCES `{prefix}users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}rate_limits` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `bucket` VARCHAR(255) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_rate_bucket` (`bucket`(191), `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}boards` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `owner_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `description` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_boards_owner` (`owner_id`),
  CONSTRAINT `fk_{prefix}boards_owner` FOREIGN KEY (`owner_id`) REFERENCES `{prefix}users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}board_members` (
  `board_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `role` ENUM('owner','editor','viewer') NOT NULL DEFAULT 'editor',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`board_id`, `user_id`),
  KEY `idx_members_user` (`user_id`),
  CONSTRAINT `fk_{prefix}members_board` FOREIGN KEY (`board_id`) REFERENCES `{prefix}boards` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_{prefix}members_user` FOREIGN KEY (`user_id`) REFERENCES `{prefix}users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}board_invitations` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `board_id` INT UNSIGNED NOT NULL,
  `email` VARCHAR(190) NOT NULL,
  `role` ENUM('editor','viewer') NOT NULL DEFAULT 'editor',
  `invited_by` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_invitation_board_email` (`board_id`, `email`),
  KEY `idx_invitation_email` (`email`),
  CONSTRAINT `fk_{prefix}invitations_board` FOREIGN KEY (`board_id`) REFERENCES `{prefix}boards` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_{prefix}invitations_user` FOREIGN KEY (`invited_by`) REFERENCES `{prefix}users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}board_columns` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `board_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `position` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_columns_board` (`board_id`, `position`),
  CONSTRAINT `fk_{prefix}columns_board` FOREIGN KEY (`board_id`) REFERENCES `{prefix}boards` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `{prefix}cards` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `board_id` INT UNSIGNED NOT NULL,
  `column_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(200) NOT NULL,
  `description` TEXT NULL,
  `position` INT NOT NULL DEFAULT 0,
  `assignee_id` INT UNSIGNED NULL,
  `created_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cards_column` (`column_id`, `position`),
  KEY `idx_cards_board` (`board_id`),
  CONSTRAINT `fk_{prefix}cards_board` FOREIGN KEY (`board_id`) REFERENCES `{prefix}boards` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_{prefix}cards_column` FOREIGN KEY (`column_id`) REFERENCES `{prefix}board_columns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_{prefix}cards_assignee` FOREIGN KEY (`assignee_id`) REFERENCES `{prefix}users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_{prefix}cards_creator` FOREIGN KEY (`created_by`) REFERENCES `{prefix}users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
