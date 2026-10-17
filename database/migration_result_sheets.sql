-- Result-sheet uploads for election_monitor_app.
-- Non-destructive: creates the upload table if it is missing.
-- It does not drop tables or change polling-unit results.
--
--   mysql -u root election_monitor_app < database/migration_result_sheets.sql

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS result_sheet_uploads (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    polling_unit_id INT UNSIGNED NOT NULL,
    stored_name CHAR(40) NOT NULL,
    original_name VARCHAR(180) NOT NULL,
    mime VARCHAR(40) NOT NULL,
    byte_size INT UNSIGNED NOT NULL,
    uploaded_by INT UNSIGNED NULL,
    uploaded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_result_sheet_stored (stored_name),
    KEY idx_result_sheet_unit (polling_unit_id, uploaded_at),
    KEY idx_result_sheet_active (is_active, id),
    CONSTRAINT fk_result_sheet_unit
        FOREIGN KEY (polling_unit_id) REFERENCES polling_units (id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
    CONSTRAINT fk_result_sheet_user
        FOREIGN KEY (uploaded_by) REFERENCES users (id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
