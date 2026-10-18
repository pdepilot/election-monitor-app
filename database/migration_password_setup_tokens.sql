-- Single-use, time-limited password-setup tokens for polling agents.
-- Additive and non-destructive: creates one table if missing.
-- Does not drop tables, delete rows, or change existing accounts, units, or results.
--
--   mysql -u root election_monitor_app < database/migration_password_setup_tokens.sql
--
-- Production deployments should exclude the .git directory from the web root where practical.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS password_setup_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    purpose VARCHAR(32) NOT NULL DEFAULT 'account_setup',
    selector CHAR(24) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_password_setup_selector (selector),
    KEY idx_password_setup_user (user_id, purpose, used_at),
    CONSTRAINT fk_password_setup_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
