-- WardStock — cumulative upgrade script, 2.0.0 -> current (2.2)
--
-- Run this ONCE in phpMyAdmin's SQL tab, regardless of which 2.x.x
-- release your database is actually at (2.0.0, 2.1.2, already fully
-- current — doesn't matter). Every step below checks whether it's
-- already been applied before doing anything, so running this on a
-- database that's already partway (or fully) upgraded is a safe no-op
-- for whatever's already there — this is the ONE file to run for any
-- 2.x-line upgrade, not one file per release.
--
-- Coming from a 1.x.x install? Run sql/upgrade_from_1.0.0.sql FIRST to
-- get to the end of the 1.x line, then this one.
--
-- This file is a living document, not a one-time script — every future
-- 2.x.x release that changes sql/ gets ITS migration appended below
-- (guarded the same way), and the version stamp at the bottom updated
-- to match. A new file only appears at the next major version bump.

-- 2.1: HA sync call logging (ha_sync_log table).
CREATE TABLE IF NOT EXISTS ha_sync_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    endpoint VARCHAR(30) NOT NULL,
    called_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status_code VARCHAR(30) NOT NULL,
    detail TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2.2: GoDaddy status page (system_status_reports table).
CREATE TABLE IF NOT EXISTS system_status_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category VARCHAR(20) NOT NULL,
    component VARCHAR(50) NOT NULL,
    last_run_at DATETIME NULL,
    last_status VARCHAR(20) NULL,
    last_error TEXT NULL,
    detail TEXT NULL,
    expected_frequency_minutes INT NULL,
    reported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY category_component (category, component)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Version tracking (always safe to (re-)stamp — this is the current
-- state of the 2.x line as of this file's last update, Major.SQL = "2.2").
INSERT INTO app_settings (setting_key, setting_value) VALUES ('db_version', '2.2')
ON DUPLICATE KEY UPDATE setting_value = '2.2';
