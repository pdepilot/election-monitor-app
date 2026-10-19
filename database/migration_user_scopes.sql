-- Geographic access scopes for supervisor, collation_officer, and viewer.
-- Additive and non-destructive. Does not assign anyone by default (empty = no scoped access).
-- Does not modify polling_units.agent_user_id or existing agent assignments.
--
--   mysql -u root election_monitor_app < database/migration_user_scopes.sql

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS user_ward_scopes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    ward_id INT UNSIGNED NOT NULL,
    assigned_by INT UNSIGNED NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_ward_scope (user_id, ward_id),
    KEY idx_user_ward_scope_ward (ward_id),
    CONSTRAINT fk_user_ward_scope_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_user_ward_scope_ward
        FOREIGN KEY (ward_id) REFERENCES wards (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_user_ward_scope_actor
        FOREIGN KEY (assigned_by) REFERENCES users (id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_unit_scopes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    polling_unit_id INT UNSIGNED NOT NULL,
    assigned_by INT UNSIGNED NULL,
    assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_unit_scope (user_id, polling_unit_id),
    KEY idx_user_unit_scope_unit (polling_unit_id),
    CONSTRAINT fk_user_unit_scope_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_user_unit_scope_unit
        FOREIGN KEY (polling_unit_id) REFERENCES polling_units (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_user_unit_scope_actor
        FOREIGN KEY (assigned_by) REFERENCES users (id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
