-- Oguta LGA ward targets for election_monitor_app.
-- Non-destructive: inserts missing wards and refreshes expected_units.
-- Does not delete Ohaji/Egbema wards or polling units.
--
-- EGWE/EGBUOMA (16-16-10) is intentionally omitted until codes/names are complete.
--
--   mysql -u root election_monitor_app < database/migration_oguta_wards.sql

SET NAMES utf8mb4;

INSERT INTO wards (name, expected_units) VALUES
    ('AWA', 22),
    ('IZOMBE', 20),
    ('MBALA/UBA', 12),
    ('NDEULOUKWU/UMUOWERE', 7),
    ('OBUDI/ARO', 16),
    ('OGUTA A', 21),
    ('OGUTA B', 13),
    ('ORU', 25),
    ('OSSEMOTOR/ENUIGBO', 9),
    ('UWAORIE', 19)
ON DUPLICATE KEY UPDATE expected_units = VALUES(expected_units);
