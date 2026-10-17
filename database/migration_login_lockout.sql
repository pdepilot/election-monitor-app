-- Election Monitoring Portal
-- Account lockout after repeated failed sign-in attempts.
-- Adds columns to the existing `users` table. Does not drop, rename, or overwrite data.
--
--   mysql -u root election_monitor_app < database/migration_login_lockout.sql

SET NAMES utf8mb4;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS failed_login_count TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER is_active,
    ADD COLUMN IF NOT EXISTS locked_at DATETIME NULL AFTER failed_login_count;

ALTER TABLE users
    ADD INDEX IF NOT EXISTS idx_users_locked (locked_at);
