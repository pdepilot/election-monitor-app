-- Ward and constituency collation for election_monitor_app.
-- Non-destructive: creates tables if they are missing.
-- It does not drop tables or change polling-unit results.
--
--   mysql -u root election_monitor_app < database/migration_collation.sql

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS ward_collations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ward_id INT UNSIGNED NOT NULL,
    accredited_voters INT UNSIGNED NOT NULL,
    rejected_ballots INT UNSIGNED NOT NULL,
    unit_count INT UNSIGNED NOT NULL,
    collated_by INT UNSIGNED NULL,
    collated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ward_collation (ward_id),
    CONSTRAINT fk_ward_collation_ward
        FOREIGN KEY (ward_id) REFERENCES wards (id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
    CONSTRAINT fk_ward_collation_user
        FOREIGN KEY (collated_by) REFERENCES users (id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ward_collation_votes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    collation_id INT UNSIGNED NOT NULL,
    candidate_id INT UNSIGNED NOT NULL,
    votes INT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ward_collation_candidate (collation_id, candidate_id),
    CONSTRAINT fk_ward_collation_vote
        FOREIGN KEY (collation_id) REFERENCES ward_collations (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT fk_ward_collation_candidate
        FOREIGN KEY (candidate_id) REFERENCES candidates (id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS constituency_collations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    scope_key CHAR(1) NOT NULL DEFAULT '1',
    accredited_voters INT UNSIGNED NOT NULL,
    rejected_ballots INT UNSIGNED NOT NULL,
    ward_count INT UNSIGNED NOT NULL,
    collated_by INT UNSIGNED NULL,
    collated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_constituency_scope (scope_key),
    CONSTRAINT fk_constituency_collation_user
        FOREIGN KEY (collated_by) REFERENCES users (id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS constituency_collation_votes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    collation_id INT UNSIGNED NOT NULL,
    candidate_id INT UNSIGNED NOT NULL,
    votes INT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_constituency_collation_candidate (collation_id, candidate_id),
    CONSTRAINT fk_constituency_collation_vote
        FOREIGN KEY (collation_id) REFERENCES constituency_collations (id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT fk_constituency_collation_candidate
        FOREIGN KEY (candidate_id) REFERENCES candidates (id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
