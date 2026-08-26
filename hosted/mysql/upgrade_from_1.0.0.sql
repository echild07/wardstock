-- WardStock — cumulative upgrade script, 1.0.0 -> end of the 1.x line (1.1.3)
--
-- Run this ONCE in phpMyAdmin's SQL tab if your database has never been
-- upgraded past a 1.x install. Safe to run from ANY point in the 1.x
-- line (including a completely untouched 1.0.0 install) — every step
-- below checks whether it's already been applied before doing anything,
-- so re-running this script on a database that's already partway (or
-- fully) upgraded is a safe no-op for whatever's already there.
--
-- Going from 1.x.x to the current 2.x.x release? Run this file FIRST to
-- get to the end of the 1.x line, then run sql/upgrade_from_2.0.0.sql.
-- If you're already on any 2.x.x release, skip this file entirely.

-- Generic helper: add a column only if it doesn't already exist. Defined
-- once, used below, dropped again at the end — not a permanent fixture.
DROP PROCEDURE IF EXISTS lucius_add_column_if_missing;
DELIMITER $$
CREATE PROCEDURE lucius_add_column_if_missing(
    IN p_table VARCHAR(64), IN p_column VARCHAR(64), IN p_definition VARCHAR(255)
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = p_table AND COLUMN_NAME = p_column
    ) THEN
        SET @lucius_ddl = CONCAT('ALTER TABLE ', p_table, ' ADD COLUMN ', p_column, ' ', p_definition);
        PREPARE lucius_stmt FROM @lucius_ddl;
        EXECUTE lucius_stmt;
        DEALLOCATE PREPARE lucius_stmt;
    END IF;
END$$
DELIMITER ;

-- Oura Ring integration: base table, in case this is a genuinely
-- untouched 1.0.0 install that never ran the original alter script.
CREATE TABLE IF NOT EXISTS oura_tokens (
    id INT PRIMARY KEY DEFAULT 1,
    access_token TEXT,
    refresh_token TEXT,
    expires_at DATETIME,
    connected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Oura Ring integration: last-connection tracking columns.
CALL lucius_add_column_if_missing('oura_tokens', 'last_success_at', 'DATETIME NULL AFTER expires_at');
CALL lucius_add_column_if_missing('oura_tokens', 'last_attempt_at', 'DATETIME NULL AFTER last_success_at');
CALL lucius_add_column_if_missing('oura_tokens', 'last_attempt_ok', 'TINYINT(1) NULL AFTER last_attempt_at');

DROP PROCEDURE lucius_add_column_if_missing;

-- Medication due-date recurrence: add frequency_days AND backfill it from
-- each medication's existing cadence label — but ONLY on the run that
-- actually adds the column. Bundled into one guarded block (not the
-- generic helper above) specifically so this never re-runs the backfill
-- against a database where you've since hand-corrected a specific
-- medication's frequency_days — this script must never silently clobber
-- a manual correction on a later run.
DROP PROCEDURE IF EXISTS lucius_migrate_medication_frequency;
DELIMITER $$
CREATE PROCEDURE lucius_migrate_medication_frequency()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'medications' AND COLUMN_NAME = 'frequency_days'
    ) THEN
        ALTER TABLE medications ADD COLUMN frequency_days INT NOT NULL DEFAULT 1 AFTER cadence;
        UPDATE medications SET frequency_days = CASE
            WHEN cadence LIKE '%biweekly%' OR cadence LIKE '%every 2 week%' THEN 14
            WHEN cadence LIKE '%weekly%' THEN 7
            WHEN cadence LIKE '%every other day%' THEN 2
            WHEN cadence LIKE '%monthly%' THEN 30
            ELSE 1
        END
        WHERE med_type = 'scheduled';
    END IF;
END$$
DELIMITER ;
CALL lucius_migrate_medication_frequency();
DROP PROCEDURE lucius_migrate_medication_frequency;

-- Version tracking (always safe to (re-)stamp — this is the end state of
-- the 1.x line, Major.SQL = "1.1").
INSERT INTO app_settings (setting_key, setting_value) VALUES ('db_version', '1.1')
ON DUPLICATE KEY UPDATE setting_value = '1.1';
