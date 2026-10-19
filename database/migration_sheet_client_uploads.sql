-- Client upload-ID receipts for idempotent offline result-sheet uploads.
-- Additive only. Does not alter existing result_sheet_uploads rows or HMAC keys.
--
--   mysql -u root election_monitor_app < database/migration_sheet_client_uploads.sql

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS result_sheet_client_uploads (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_upload_id CHAR(36) NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    polling_unit_id INT UNSIGNED NOT NULL,
    content_sha256 CHAR(64) NOT NULL,
    upload_kind VARCHAR(32) NOT NULL,
    upload_reason VARCHAR(255) NOT NULL,
    original_name VARCHAR(180) NOT NULL,
    mime VARCHAR(40) NOT NULL,
    byte_size INT UNSIGNED NOT NULL,
    result_sheet_upload_id INT UNSIGNED NOT NULL,
    received_at_utc DATETIME(3) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sheet_client_upload_id (client_upload_id),
    KEY idx_sheet_client_upload_user (user_id, received_at_utc),
    KEY idx_sheet_client_upload_unit (polling_unit_id),
    CONSTRAINT fk_sheet_client_upload_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_sheet_client_upload_unit
        FOREIGN KEY (polling_unit_id) REFERENCES polling_units (id)
        ON DELETE RESTRICT,
    CONSTRAINT fk_sheet_client_upload_sheet
        FOREIGN KEY (result_sheet_upload_id) REFERENCES result_sheet_uploads (id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
