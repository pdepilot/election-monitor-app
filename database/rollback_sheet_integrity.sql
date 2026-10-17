-- Rollback for migration_sheet_integrity.sql. NOT applied automatically.
-- WARNING: this permanently removes SHA-256 fingerprints, metadata signatures, version history,
-- review records, the signed event trail, and integrity incidents. Export them first.
-- Uploaded files and the original result_sheet_uploads rows are kept.
--
--   mysql -u root election_monitor_app < database/rollback_sheet_integrity.sql

SET NAMES utf8mb4;

DROP TABLE IF EXISTS result_sheet_incidents;
DROP TABLE IF EXISTS result_sheet_events;

ALTER TABLE result_sheet_uploads
    DROP INDEX IF EXISTS uq_result_sheet_version,
    DROP INDEX IF EXISTS idx_result_sheet_supersedes;

ALTER TABLE result_sheet_uploads
    DROP COLUMN IF EXISTS version_no,
    DROP COLUMN IF EXISTS supersedes_id,
    DROP COLUMN IF EXISTS upload_kind,
    DROP COLUMN IF EXISTS upload_reason,
    DROP COLUMN IF EXISTS storage_area,
    DROP COLUMN IF EXISTS sha256,
    DROP COLUMN IF EXISTS uploaded_at_utc,
    DROP COLUMN IF EXISTS meta_hmac,
    DROP COLUMN IF EXISTS hmac_key_id,
    DROP COLUMN IF EXISTS review_status,
    DROP COLUMN IF EXISTS reviewed_by,
    DROP COLUMN IF EXISTS reviewed_at_utc,
    DROP COLUMN IF EXISTS review_note,
    DROP COLUMN IF EXISTS legacy_baseline_sha256,
    DROP COLUMN IF EXISTS legacy_baseline_at_utc;
