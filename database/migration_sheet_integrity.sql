-- Result-sheet integrity protection for election_monitor_app.
-- Additive only: adds columns and tables. It does not drop, rename, or rewrite data.
-- Safe to run more than once (IF NOT EXISTS everywhere).
-- Rows that exist before this migration keep sha256 = NULL and are shown as UNVERIFIED LEGACY.
--
--   mysql -u root election_monitor_app < database/migration_sheet_integrity.sql
--
-- Rollback (removes the new columns and tables, and with them all integrity evidence):
--   database/rollback_sheet_integrity.sql

SET NAMES utf8mb4;

ALTER TABLE result_sheet_uploads
    ADD COLUMN IF NOT EXISTS version_no INT UNSIGNED NULL AFTER polling_unit_id,
    ADD COLUMN IF NOT EXISTS supersedes_id INT UNSIGNED NULL AFTER version_no,
    ADD COLUMN IF NOT EXISTS upload_kind VARCHAR(16) NULL AFTER supersedes_id,
    ADD COLUMN IF NOT EXISTS upload_reason VARCHAR(255) NULL AFTER upload_kind,
    ADD COLUMN IF NOT EXISTS storage_area VARCHAR(16) NOT NULL DEFAULT 'legacy' AFTER stored_name,
    ADD COLUMN IF NOT EXISTS sha256 CHAR(64) NULL AFTER byte_size,
    ADD COLUMN IF NOT EXISTS uploaded_at_utc DATETIME NULL AFTER uploaded_at,
    ADD COLUMN IF NOT EXISTS meta_hmac CHAR(64) NULL AFTER uploaded_at_utc,
    ADD COLUMN IF NOT EXISTS hmac_key_id VARCHAR(32) NULL AFTER meta_hmac,
    ADD COLUMN IF NOT EXISTS review_status VARCHAR(16) NOT NULL DEFAULT 'pending' AFTER is_active,
    ADD COLUMN IF NOT EXISTS reviewed_by INT UNSIGNED NULL AFTER review_status,
    ADD COLUMN IF NOT EXISTS reviewed_at_utc DATETIME NULL AFTER reviewed_by,
    ADD COLUMN IF NOT EXISTS review_note VARCHAR(255) NULL AFTER reviewed_at_utc,
    ADD COLUMN IF NOT EXISTS legacy_baseline_sha256 CHAR(64) NULL AFTER review_note,
    ADD COLUMN IF NOT EXISTS legacy_baseline_at_utc DATETIME NULL AFTER legacy_baseline_sha256;

ALTER TABLE result_sheet_uploads
    ADD UNIQUE INDEX IF NOT EXISTS uq_result_sheet_version (polling_unit_id, version_no),
    ADD INDEX IF NOT EXISTS idx_result_sheet_supersedes (supersedes_id);

-- Append-only event trail. Each signed row carries the previous row's HMAC (prev_chain),
-- so removing or editing a row breaks verification of every later row.
-- No foreign keys: deleting a user or upload must never rewrite evidence rows.
CREATE TABLE IF NOT EXISTS result_sheet_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    upload_id INT UNSIGNED NULL,
    polling_unit_id INT UNSIGNED NULL,
    actor_user_id INT UNSIGNED NULL,
    event_type VARCHAR(40) NOT NULL,
    previous_upload_id INT UNSIGNED NULL,
    new_upload_id INT UNSIGNED NULL,
    detail VARCHAR(255) NOT NULL DEFAULT '',
    occurred_at_utc DATETIME NOT NULL,
    prev_chain CHAR(64) NULL,
    event_hmac CHAR(64) NULL,
    hmac_key_id VARCHAR(32) NULL,
    PRIMARY KEY (id),
    KEY idx_sheet_event_upload (upload_id, id),
    KEY idx_sheet_event_unit (polling_unit_id, id),
    KEY idx_sheet_event_type (event_type, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Integrity incidents stay open until a person resolves them. The application never closes them.
CREATE TABLE IF NOT EXISTS result_sheet_incidents (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    upload_id INT UNSIGNED NOT NULL,
    incident_type VARCHAR(32) NOT NULL,
    observed_sha256 CHAR(64) NULL,
    detected_by INT UNSIGNED NULL,
    detected_at_utc DATETIME NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'open',
    resolved_by INT UNSIGNED NULL,
    resolved_at_utc DATETIME NULL,
    resolution_note VARCHAR(255) NULL,
    PRIMARY KEY (id),
    KEY idx_sheet_incident_upload (upload_id, status),
    KEY idx_sheet_incident_status (status, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
