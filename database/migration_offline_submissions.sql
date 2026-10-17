-- Idempotent offline result submissions for election_monitor_app.
-- Additive and non-destructive: creates one table if it is missing.
-- It does not drop tables, delete rows, or change existing results.
--
--   mysql -u root election_monitor_app < database/migration_offline_submissions.sql

SET NAMES utf8mb4;

-- One row per submission the server accepted. The unique submission_id makes retries of the
-- same device submission return the original outcome instead of creating a second result.
CREATE TABLE IF NOT EXISTS result_submissions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    submission_id CHAR(36) NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    polling_unit_id INT UNSIGNED NOT NULL,
    result_id INT UNSIGNED NOT NULL,
    schema_version TINYINT UNSIGNED NOT NULL,
    payload_sha256 CHAR(64) NOT NULL,
    figures_sha256 CHAR(64) NOT NULL,
    client_created_at VARCHAR(32) NULL,
    received_at_utc DATETIME(3) NOT NULL,
    response_json TEXT NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_result_submission_id (submission_id),
    KEY idx_result_submission_user (user_id, id),
    KEY idx_result_submission_unit (polling_unit_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
