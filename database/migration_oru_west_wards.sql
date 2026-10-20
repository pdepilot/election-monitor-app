-- Oru West LGA ward targets for election_monitor_app.
-- Non-destructive: inserts missing wards and refreshes expected_units.
-- Does not delete Ohaji/Egbema or Oguta wards or polling units.
--
-- NEMPI/ELEM expected_units is 19 (verified 16-24-01-001..019).
-- Two listed rows with codes 16-23-09-021 and 16-23-09-022 are omitted
-- until correct Oru West codes are confirmed (claimed ward total was 22).
--
--   mysql -u root election_monitor_app < database/migration_oru_west_wards.sql

SET NAMES utf8mb4;

INSERT INTO wards (name, expected_units) VALUES
    ('NEMPI/ELEM', 19),
    ('OTULU', 13),
    ('AJI', 7),
    ('IBIASO EGBE', 12),
    ('AMAFUO', 5),
    ('OHAKPU', 7),
    ('OZARA', 9),
    ('UBULU', 24),
    ('MGBIDI I', 21),
    ('MGBIDI II', 30)
ON DUPLICATE KEY UPDATE expected_units = VALUES(expected_units);
