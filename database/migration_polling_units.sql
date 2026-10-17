-- Polling unit management for election_monitor_app.
-- Non-destructive: creates tables if they are missing and inserts the 12 ward
-- targets. It does not drop tables, delete rows, or rewrite polling units.
--
-- The expected counts are validation targets supplied for Ohaji/Egbema.
-- They are not an INEC verification of the polling-unit register.
--
--   mysql -u root election_monitor_app < database/migration_polling_units.sql

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS wards (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(80) NOT NULL,
    expected_units SMALLINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_wards_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS polling_units (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    unit_code VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
    name VARCHAR(150) NOT NULL,
    ward_id INT UNSIGNED NOT NULL,
    lga_name VARCHAR(80) NOT NULL,
    classification ENUM('EXISTING PU', 'NEW PU') NOT NULL,
    agent_user_id INT UNSIGNED NULL,
    assigned_by INT UNSIGNED NULL,
    assigned_at DATETIME NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_polling_units_code (unit_code),
    KEY idx_polling_units_ward (ward_id),
    KEY idx_polling_units_classification (classification),
    KEY idx_polling_units_agent (agent_user_id),
    KEY idx_polling_units_active (is_active),
    CONSTRAINT fk_polling_units_ward
        FOREIGN KEY (ward_id) REFERENCES wards (id)
        ON DELETE RESTRICT
        ON UPDATE CASCADE,
    CONSTRAINT fk_polling_units_agent
        FOREIGN KEY (agent_user_id) REFERENCES users (id)
        ON DELETE SET NULL
        ON UPDATE CASCADE,
    CONSTRAINT fk_polling_units_assigner
        FOREIGN KEY (assigned_by) REFERENCES users (id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_user_id INT UNSIGNED NULL,
    action VARCHAR(64) NOT NULL,
    entity_type VARCHAR(64) NOT NULL,
    entity_id INT UNSIGNED NULL,
    summary VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_entity (entity_type, entity_id),
    KEY idx_audit_actor (actor_user_id),
    KEY idx_audit_created (created_at),
    CONSTRAINT fk_audit_actor
        FOREIGN KEY (actor_user_id) REFERENCES users (id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO wards (name, expected_units) VALUES
    ('ASSA/OBILE', 16),
    ('AWARA/IKWEREDE', 20),
    ('EGBEMA A', 27),
    ('EGBEMA B', 12),
    ('EGBEMA C', 12),
    ('EGBEMA D', 8),
    ('EGBEMA E', 11),
    ('EKWUATO', 22),
    ('OBITTI/MGBISHI', 18),
    ('OHOBA', 27),
    ('UMUAGWO', 24),
    ('UMUAPU', 13)
ON DUPLICATE KEY UPDATE expected_units = VALUES(expected_units);
