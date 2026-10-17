-- Polling-agent assignment emails for election_monitor_app.
-- Additive and non-destructive: adds two columns to users and creates one table if missing.
-- It does not drop tables, delete rows, or change existing accounts, units, or results.
-- Existing accounts keep must_change_password = 0.
--
--   mysql -u root election_monitor_app < database/migration_agent_notifications.sql

SET NAMES utf8mb4;

-- An agent whose password was chosen by an administrator and sent by email must replace it
-- at first sign-in. Only the password_hash() value is stored; the emailed password is not kept.
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active,
    ADD COLUMN IF NOT EXISTS password_changed_at DATETIME NULL AFTER must_change_password;

-- One row per assignment email. The message body and any password are never stored here.
-- The units listed are re-read from polling_units at send time, so a retry is never stale.
CREATE TABLE IF NOT EXISTS agent_notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    notification_id CHAR(36) NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    kind ENUM('account_created', 'assignment_changed') NOT NULL,
    includes_password TINYINT(1) NOT NULL DEFAULT 0,
    recipient_email VARCHAR(255) NOT NULL,
    status ENUM('queued', 'sending', 'sent', 'captured', 'failed', 'not_configured', 'superseded') NOT NULL DEFAULT 'queued',
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    transport VARCHAR(16) NULL,
    last_error VARCHAR(64) NULL,
    unit_codes VARCHAR(1000) NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    sent_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_agent_notification_id (notification_id),
    KEY idx_agent_notification_user (user_id, status),
    CONSTRAINT fk_agent_notification_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
