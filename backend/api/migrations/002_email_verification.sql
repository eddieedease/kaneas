-- Email verification for new registrations.
-- `{prefix}` is replaced with the table prefix. Existing users are marked as verified.

ALTER TABLE `{prefix}users` ADD COLUMN `email_verified_at` DATETIME NULL AFTER `is_active`;

UPDATE `{prefix}users` SET `email_verified_at` = `created_at` WHERE `email_verified_at` IS NULL;

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
