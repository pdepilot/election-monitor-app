-- Result entry validation and reviewer comparison for election_monitor_app.
-- Additive and non-destructive: it adds nullable columns and new tables only.
-- It does not drop tables, delete rows, or change existing figures.
--
--   mysql -u root election_monitor_app < database/migration_result_review.sql

SET NAMES utf8mb4;

-- Drafts may be incomplete, so the figures accept NULL until submission.
ALTER TABLE polling_unit_results
    MODIFY accredited_voters INT UNSIGNED NULL DEFAULT NULL,
    MODIFY rejected_ballots INT UNSIGNED NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS declared_valid_votes INT UNSIGNED NULL DEFAULT NULL AFTER rejected_ballots,
    ADD COLUMN IF NOT EXISTS declared_total_votes INT UNSIGNED NULL DEFAULT NULL AFTER declared_valid_votes;

ALTER TABLE polling_unit_result_votes
    MODIFY votes INT UNSIGNED NULL DEFAULT NULL;

-- One row per comparison of entered figures with an uploaded result sheet. Never updated or deleted.
CREATE TABLE IF NOT EXISTS result_reviews (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    result_id INT UNSIGNED NOT NULL,
    polling_unit_id INT UNSIGNED NOT NULL,
    upload_id INT UNSIGNED NOT NULL,
    reviewer_id INT UNSIGNED NOT NULL,
    outcome ENUM('match', 'discrepancy') NOT NULL,
    figures_sha256 CHAR(64) NOT NULL,
    note VARCHAR(255) NULL,
    reviewed_at_utc DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_review_unit (polling_unit_id, id),
    KEY idx_review_result (result_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per figure that differed from the sheet. Resolution adds a note; it never edits figures.
CREATE TABLE IF NOT EXISTS result_discrepancies (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    review_id INT UNSIGNED NOT NULL,
    result_id INT UNSIGNED NOT NULL,
    polling_unit_id INT UNSIGNED NOT NULL,
    field VARCHAR(40) NOT NULL,
    candidate_id INT UNSIGNED NULL,
    entered_value INT UNSIGNED NULL,
    sheet_value INT UNSIGNED NULL,
    status ENUM('open', 'resolved') NOT NULL DEFAULT 'open',
    resolved_by INT UNSIGNED NULL,
    resolved_at_utc DATETIME NULL,
    resolution_note VARCHAR(255) NULL,
    PRIMARY KEY (id),
    KEY idx_discrepancy_unit (polling_unit_id, status),
    KEY idx_discrepancy_review (review_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
