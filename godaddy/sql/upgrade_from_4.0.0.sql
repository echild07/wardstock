-- WardStock — cumulative upgrade script, 4.0.0 -> current (4.x line, "Drew")
--
-- Run this ONCE in phpMyAdmin's SQL tab for any 4.x install. Safe to run
-- from any point in the 4.x line, same convention as upgrade_from_3.0.0.sql.
--
-- Prepares a database FOR 4.0.0 — table-prefix convention (Ward, Aug
-- 2026): every LeeWard product's tables get renamed `{product}_{tablename}`,
-- so multiple products can eventually share one MySQL database without
-- name collisions. See leeward/STANDARDS.md §5 for the company-wide
-- rule. WardStock's own column/relationship structure is UNCHANGED —
-- this is a rename-only migration, every RENAME TABLE below is
-- idempotent (checked against information_schema first, so re-running
-- this file after it's already applied is a safe no-op). Still stamps
-- db_version "4.0", not "4.1" — this repo hasn't deployed 4.0.0 live yet,
-- so this work is folded into that same not-yet-shipped version rather
-- than advancing past it. The version number only moves at actual
-- deploy time (leeward/STANDARDS.md §3), not at every local change.
--
-- Coming from the 3.x line? Run upgrade_from_3.0.0.sql first if you haven't
-- already (any 3.x-line schema change lives there, not here) — this file
-- only covers what's new for 4.x.
--
-- This file is a living document, not a one-time script — every future
-- 4.x.x release that changes sql/ gets ITS migration appended below,
-- version stamp at the bottom updated to match. A new file only appears
-- at the next major version bump.

-- Idempotent RENAME TABLE helper — same PREPARE/EXECUTE pattern
-- upgrade_from_3.0.0.sql already uses for conditional DDL. Only renames
-- when the old name still exists AND the new name doesn't yet (so this
-- is safe whether run against a genuinely-old 4.0.0 database or one
-- that's already been renamed by an earlier run of this same file).
SET @lucius_old_exists = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'incidents'
);
SET @lucius_new_exists = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wardstock_incidents'
);
SET @lucius_ddl = IF(@lucius_old_exists > 0 AND @lucius_new_exists = 0, 'RENAME TABLE incidents TO wardstock_incidents', 'SELECT 1');
PREPARE lucius_stmt FROM @lucius_ddl;
EXECUTE lucius_stmt;
DEALLOCATE PREPARE lucius_stmt;

SET @lucius_old_exists = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'daily_logs'
);
SET @lucius_new_exists = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wardstock_daily_logs'
);
SET @lucius_ddl = IF(@lucius_old_exists > 0 AND @lucius_new_exists = 0, 'RENAME TABLE daily_logs TO wardstock_daily_logs', 'SELECT 1');
PREPARE lucius_stmt FROM @lucius_ddl;
EXECUTE lucius_stmt;
DEALLOCATE PREPARE lucius_stmt;

SET @lucius_old_exists = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'medications'
);
SET @lucius_new_exists = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wardstock_medications'
);
SET @lucius_ddl = IF(@lucius_old_exists > 0 AND @lucius_new_exists = 0, 'RENAME TABLE medications TO wardstock_medications', 'SELECT 1');
PREPARE lucius_stmt FROM @lucius_ddl;
EXECUTE lucius_stmt;
DEALLOCATE PREPARE lucius_stmt;

SET @lucius_old_exists = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'medication_dosage_history'
);
SET @lucius_new_exists = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wardstock_medication_dosage_history'
);
SET @lucius_ddl = IF(@lucius_old_exists > 0 AND @lucius_new_exists = 0, 'RENAME TABLE medication_dosage_history TO wardstock_medication_dosage_history', 'SELECT 1');
PREPARE lucius_stmt FROM @lucius_ddl;
EXECUTE lucius_stmt;
DEALLOCATE PREPARE lucius_stmt;

SET @lucius_old_exists = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'therapy_sessions'
);
SET @lucius_new_exists = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wardstock_therapy_sessions'
);
SET @lucius_ddl = IF(@lucius_old_exists > 0 AND @lucius_new_exists = 0, 'RENAME TABLE therapy_sessions TO wardstock_therapy_sessions', 'SELECT 1');
PREPARE lucius_stmt FROM @lucius_ddl;
EXECUTE lucius_stmt;
DEALLOCATE PREPARE lucius_stmt;

SET @lucius_old_exists = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'therapy_schedules'
);
SET @lucius_new_exists = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wardstock_therapy_schedules'
);
SET @lucius_ddl = IF(@lucius_old_exists > 0 AND @lucius_new_exists = 0, 'RENAME TABLE therapy_schedules TO wardstock_therapy_schedules', 'SELECT 1');
PREPARE lucius_stmt FROM @lucius_ddl;
EXECUTE lucius_stmt;
DEALLOCATE PREPARE lucius_stmt;

SET @lucius_old_exists = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'oura_tokens'
);
SET @lucius_new_exists = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wardstock_oura_tokens'
);
SET @lucius_ddl = IF(@lucius_old_exists > 0 AND @lucius_new_exists = 0, 'RENAME TABLE oura_tokens TO wardstock_oura_tokens', 'SELECT 1');
PREPARE lucius_stmt FROM @lucius_ddl;
EXECUTE lucius_stmt;
DEALLOCATE PREPARE lucius_stmt;

SET @lucius_old_exists = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'app_settings'
);
SET @lucius_new_exists = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wardstock_app_settings'
);
SET @lucius_ddl = IF(@lucius_old_exists > 0 AND @lucius_new_exists = 0, 'RENAME TABLE app_settings TO wardstock_app_settings', 'SELECT 1');
PREPARE lucius_stmt FROM @lucius_ddl;
EXECUTE lucius_stmt;
DEALLOCATE PREPARE lucius_stmt;

SET @lucius_old_exists = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'app_user'
);
SET @lucius_new_exists = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wardstock_app_user'
);
SET @lucius_ddl = IF(@lucius_old_exists > 0 AND @lucius_new_exists = 0, 'RENAME TABLE app_user TO wardstock_app_user', 'SELECT 1');
PREPARE lucius_stmt FROM @lucius_ddl;
EXECUTE lucius_stmt;
DEALLOCATE PREPARE lucius_stmt;

SET @lucius_old_exists = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ha_sync_log'
);
SET @lucius_new_exists = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wardstock_ha_sync_log'
);
SET @lucius_ddl = IF(@lucius_old_exists > 0 AND @lucius_new_exists = 0, 'RENAME TABLE ha_sync_log TO wardstock_ha_sync_log', 'SELECT 1');
PREPARE lucius_stmt FROM @lucius_ddl;
EXECUTE lucius_stmt;
DEALLOCATE PREPARE lucius_stmt;

SET @lucius_old_exists = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'system_status_reports'
);
SET @lucius_new_exists = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wardstock_system_status_reports'
);
SET @lucius_ddl = IF(@lucius_old_exists > 0 AND @lucius_new_exists = 0, 'RENAME TABLE system_status_reports TO wardstock_system_status_reports', 'SELECT 1');
PREPARE lucius_stmt FROM @lucius_ddl;
EXECUTE lucius_stmt;
DEALLOCATE PREPARE lucius_stmt;

SET @lucius_old_exists = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'analysis_results'
);
SET @lucius_new_exists = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wardstock_analysis_results'
);
SET @lucius_ddl = IF(@lucius_old_exists > 0 AND @lucius_new_exists = 0, 'RENAME TABLE analysis_results TO wardstock_analysis_results', 'SELECT 1');
PREPARE lucius_stmt FROM @lucius_ddl;
EXECUTE lucius_stmt;
DEALLOCATE PREPARE lucius_stmt;

SET @lucius_old_exists = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'attention_snoozes'
);
SET @lucius_new_exists = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wardstock_attention_snoozes'
);
SET @lucius_ddl = IF(@lucius_old_exists > 0 AND @lucius_new_exists = 0, 'RENAME TABLE attention_snoozes TO wardstock_attention_snoozes', 'SELECT 1');
PREPARE lucius_stmt FROM @lucius_ddl;
EXECUTE lucius_stmt;
DEALLOCATE PREPARE lucius_stmt;

SET @lucius_old_exists = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'proposed_events'
);
SET @lucius_new_exists = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wardstock_proposed_events'
);
SET @lucius_ddl = IF(@lucius_old_exists > 0 AND @lucius_new_exists = 0, 'RENAME TABLE proposed_events TO wardstock_proposed_events', 'SELECT 1');
PREPARE lucius_stmt FROM @lucius_ddl;
EXECUTE lucius_stmt;
DEALLOCATE PREPARE lucius_stmt;

SET @lucius_old_exists = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'blood_pressure_readings'
);
SET @lucius_new_exists = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wardstock_blood_pressure_readings'
);
SET @lucius_ddl = IF(@lucius_old_exists > 0 AND @lucius_new_exists = 0, 'RENAME TABLE blood_pressure_readings TO wardstock_blood_pressure_readings', 'SELECT 1');
PREPARE lucius_stmt FROM @lucius_ddl;
EXECUTE lucius_stmt;
DEALLOCATE PREPARE lucius_stmt;

SET @lucius_old_exists = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ecg_recordings'
);
SET @lucius_new_exists = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wardstock_ecg_recordings'
);
SET @lucius_ddl = IF(@lucius_old_exists > 0 AND @lucius_new_exists = 0, 'RENAME TABLE ecg_recordings TO wardstock_ecg_recordings', 'SELECT 1');
PREPARE lucius_stmt FROM @lucius_ddl;
EXECUTE lucius_stmt;
DEALLOCATE PREPARE lucius_stmt;

SET @lucius_old_exists = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ecg_artifacts'
);
SET @lucius_new_exists = (
    SELECT COUNT(*) FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wardstock_ecg_artifacts'
);
SET @lucius_ddl = IF(@lucius_old_exists > 0 AND @lucius_new_exists = 0, 'RENAME TABLE ecg_artifacts TO wardstock_ecg_artifacts', 'SELECT 1');
PREPARE lucius_stmt FROM @lucius_ddl;
EXECUTE lucius_stmt;
DEALLOCATE PREPARE lucius_stmt;

-- Version tracking (always safe to (re-)stamp — this is the current
-- state of the 4.x line as of this file's last update, Major.Data = "4.1").
-- Table renamed to wardstock_app_settings above — this INSERT already
-- targets the new name, so it's correct whether this run just did the
-- renames or they'd already happened on a previous run of this file.
INSERT INTO wardstock_app_settings (setting_key, setting_value) VALUES ('db_version', '4.0')
ON DUPLICATE KEY UPDATE setting_value = '4.0';
