-- Result submission tables for election_monitor_app.
-- Non-destructive: creates tables if they are missing.
-- It does not drop tables, delete polling units, or insert candidates.
--
--   mysql -u root election_monitor_app < database/migration_results.sql

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS candidates (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    full_name VARCHAR(150) NOT NULL,
    party VARCHAR(12) NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_candidates_party (party),
    KEY idx_candidates_active (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS polling_unit_results (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    polling_unit_id INT UNSIGNED NOT NULL,
    accredited_voters INT UNSIGNED NOT NULL DEFAULT 0,
    rejected_ballots INT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('draft', 'submitted') NOT NULL DEFAULT 'draft',
    submitted_by INT UNSIGNED NULL,
    submitted_at DATETIME NULL,
    updated_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_result_polling_unit (polling_unit_id),
    KEY idx_result_status (status),
    CONSTRAINT fk_result_polling_unit
        FOREIGN KEY (polling_unit_id) REFERENCES polling_units (id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
    CONSTRAINT fk_result_submitter
        FOREIGN KEY (submitted_by) REFERENCES users (id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,
    CONSTRAINT fk_result_updater
        FOREIGN KEY (updated_by) REFERENCES users (id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS polling_unit_result_votes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    result_id INT UNSIGNED NOT NULL,
    candidate_id INT UNSIGNED NOT NULL,
    votes INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_result_candidate (result_id, candidate_id),
    KEY idx_vote_candidate (candidate_id),
    CONSTRAINT fk_vote_result
        FOREIGN KEY (result_id) REFERENCES polling_unit_results (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT fk_vote_candidate
        FOREIGN KEY (candidate_id) REFERENCES candidates (id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
