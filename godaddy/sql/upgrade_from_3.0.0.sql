-- WardStock — cumulative upgrade script, 3.0.0 -> current (3.x line, "Fulgrim")
--
-- Run this ONCE in phpMyAdmin's SQL tab for any 3.x install. Safe to run
-- from any point in the 3.x line — every step below checks whether it's
-- already been applied before doing anything, so re-running this script
-- on a database that's already partway (or fully) upgraded is a safe
-- no-op for whatever's already there — this is the ONE file to run for
-- any 3.x-line upgrade, not one file per release.
--
-- Coming from the 2.x line? Run this file directly on top of it —
-- schema.sql's baseline already includes everything 1.x/2.x needed, this
-- file only covers what's new for 3.x. (If you're on a genuinely
-- untouched 1.x or 2.x install that's never had its own upgrade script
-- run, run upgrade_from_1.0.0.sql and upgrade_from_2.0.0.sql first.)
--
-- This file is a living document, not a one-time script — every future
-- 3.x.x release that changes sql/ gets ITS migration appended below
-- (guarded the same way), version stamp at the bottom updated to match.
-- A new file only appears at the next major version bump.

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

-- 3.1: medication dosage-change history (PLAN.md §11 #8) — same
-- medicine, different dosage, tracked over time. Ward's flagged he's
-- changing some dosages soon, so this needed to exist before that
-- happens, not after.
CREATE TABLE IF NOT EXISTS medication_dosage_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    medication_id INT NOT NULL,
    old_dosage VARCHAR(50) NULL,
    new_dosage VARCHAR(50) NOT NULL,
    changed_at DATE NOT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (medication_id) REFERENCES medications(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3.1: incidents — new `medical` category (PLAN.md §11/§19): doctor's
-- visits and their notes, medication side effects, future medical issues
-- generally — broader than side effects alone, per Ward's correction.
-- `category` already fits `medical` (7 chars) inside the existing
-- VARCHAR(10), but widened anyway to VARCHAR(20) as cheap headroom for
-- whatever category comes after this one, matching the existing
-- sensation columns' width. MODIFY COLUMN is naturally idempotent (never
-- errors on a repeat run), unlike ADD COLUMN, so this needs no guard.
ALTER TABLE incidents MODIFY COLUMN category VARCHAR(20) NOT NULL DEFAULT 'anxiety';

-- 3.1: incidents — new symptom columns for the `medical` category's
-- side-effects case specifically (headache already covered by the
-- existing headache_sensation column). Same none/mild/moderate/severe
-- convention as the existing chest/arm/shoulder/headache columns.
CALL lucius_add_column_if_missing('incidents', 'stomach_sensation', "VARCHAR(20) DEFAULT 'none' AFTER shaking");
CALL lucius_add_column_if_missing('incidents', 'flu_symptoms_sensation', "VARCHAR(20) DEFAULT 'none' AFTER stomach_sensation");
CALL lucius_add_column_if_missing('incidents', 'lethargy_sensation', "VARCHAR(20) DEFAULT 'none' AFTER flu_symptoms_sensation");

-- 3.1: incidents — optional link to a specific medication, for `medical`
-- entries (e.g. a suspected side effect), so it can name the medication
-- directly rather than relying purely on time-proximity to a dosage
-- change for the §11 #8 correlation. PLAN.md flagged this as proposed by
-- Claude and not explicitly confirmed by Ward — included anyway as a
-- nullable, zero-risk column rather than blocking the migration on it;
-- safe to just leave unused if it turns out not to be wanted.
CALL lucius_add_column_if_missing('incidents', 'related_medication_id', 'INT NULL AFTER medical_evaluation_notes');

-- 3.1: incidents — idempotency key for machine-created incidents (PLAN.md
-- §19's medical-history YAML import), since incidents otherwise have no
-- reliable natural key (see import.php's own comment on this). Never
-- set/shown on the human incident_form.php — purely for the import flow
-- to detect "already imported" on a rerun.
CALL lucius_add_column_if_missing('incidents', 'external_ref', 'VARCHAR(64) NULL UNIQUE AFTER free_notes');

-- 3.1: daily_logs — night-waking context (PLAN.md §11 #16): why Ward
-- woke and what he was thinking, not just Oura's raw sleep-stage data.
-- Simplest shape per the plan's own stated fallback — free text on the
-- day's entry, not a richer per-waking/per-Oura-awakening structure.
CALL lucius_add_column_if_missing('daily_logs', 'night_waking_notes', 'TEXT NULL AFTER free_notes');

-- 3.1: analysis_results — where wherewhen's pushed analyses/charts land
-- on the GoDaddy side (PLAN.md §11 "Where results go"). One flexible
-- table for all ~20 analyses rather than one table per analysis, since
-- each analysis's actual shape differs — result_json holds whatever that
-- analysis's chart page needs. Versioned so a full recompute (PLAN.md
-- §11 "Schedule & caching") can land new rows without deleting old ones.
CREATE TABLE IF NOT EXISTS analysis_results (
    id INT AUTO_INCREMENT PRIMARY KEY,
    analysis_key VARCHAR(50) NOT NULL,
    period_type VARCHAR(10) NOT NULL,
    period_start DATE NULL,
    period_end DATE NULL,
    analysis_version INT NOT NULL DEFAULT 1,
    result_json LONGTEXT NOT NULL,
    computed_at DATETIME NULL,
    pushed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY analysis_period_version (analysis_key, period_type, period_start, period_end, analysis_version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3.1: attention/reminder snoozes (Fulgrim, feature list §3.2/3.2.0) — one
-- row per dismissed daily reminder, keyed by reminder+due-day so a snooze
-- naturally expires the next calendar day with no cleanup job needed. See
-- app/attention.php.
CREATE TABLE IF NOT EXISTS attention_snoozes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reminder_key VARCHAR(80) NOT NULL,
    snoozed_on DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY reminder_snooze_day (reminder_key, snoozed_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3.1: "hypothetical" correlation events (Fulgrim, feature list §3.2.2) —
-- the not-yet-built Flux engine will eventually write proposed events
-- here for Ward to confirm (becomes a real incident) or deny (stays here
-- as status='denied', Ward's own "log it to be investigated" framing).
-- Queue/page built now (GoDaddy-first); producer side is future HA work.
CREATE TABLE IF NOT EXISTS proposed_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    analysis_key VARCHAR(50) NOT NULL,
    proposed_at DATETIME NOT NULL,
    suggested_occurred_at DATETIME NULL,
    suggested_category VARCHAR(20) NULL,
    description TEXT NOT NULL,
    confidence DECIMAL(4,3) NULL,
    result_json LONGTEXT NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'pending',
    reviewed_at DATETIME NULL,
    review_notes TEXT NULL,
    created_incident_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_incident_id) REFERENCES incidents(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3.1: blood pressure readings (Fulgrim, feature list §1.2) — dedicated
-- table (not daily_logs columns) since a real BP routine is 1-2+
-- timestamped readings/day, unlike weight's one-value-per-day. Entered
-- inline from the Daily Log page. See app/daily_form.php.
CREATE TABLE IF NOT EXISTS blood_pressure_readings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reading_at DATETIME NOT NULL,
    systolic SMALLINT UNSIGNED NOT NULL,
    diastolic SMALLINT UNSIGNED NOT NULL,
    pulse SMALLINT UNSIGNED NULL,
    position VARCHAR(20) NULL,
    notes TEXT NULL,
    source VARCHAR(20) NOT NULL DEFAULT 'manual',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_reading_at (reading_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3.2: preferred timezone (Ward, Aug 2026) — the wherewhen analysis
-- engine's day-boundary grouping (which calendar day a reading belongs
-- to) was hardcoded to America/New_York; Ward's own call was that this
-- should be a real user setting instead, pulled fresh into Home Assistant
-- rather than hardcoded anywhere (see app/settings.php, api/get_shared_
-- config.php, homeassistant/PLAN.md §11). INSERT IGNORE, not the db_
-- version stamp's always-overwrite pattern below — this seeds the
-- default ONLY if the row doesn't exist yet, so re-running this script
-- never clobbers a value Ward's already set via the settings page.
INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES ('preferred_timezone', 'America/New_York');

-- 3.3: analysis_results — collapse the unique key to (analysis_key,
-- period_type) instead of including period_start/period_end/
-- analysis_version (see the long comment on this table in schema.sql
-- for the full reasoning — a real design gap Ward caught: the old key
-- let every run of a rolling-window tier insert a brand new row
-- forever, since period_start shifts on every run; picking "latest
-- computed_at" to display then meant daily's much more frequent reruns
-- always buried weekly/monthly/all, which could never actually be seen
-- no matter how recently they'd run). Cleanup FIRST — purge every row
-- except the single best one per (analysis_key, period_type), "best" =
-- highest analysis_version then most recent computed_at — required
-- before the new unique key can be added, since it would otherwise
-- reject on the exact duplicates it's meant to prevent going forward.
-- Both steps below are safe to re-run: the DELETE is a no-op once
-- nothing's left to delete, and the index swap only acts if the old key
-- still exists / the new one doesn't yet (information_schema checks,
-- same dynamic-DDL pattern as lucius_add_column_if_missing above).
--
-- Real error hit running this on GoDaddy's actual MySQL/MariaDB, Aug
-- 2026: "#1093 - Table 'r' is specified twice, both as a target for
-- 'DELETE' and as a separate source for data." Some MySQL/MariaDB
-- versions reject a DELETE that references its own table in a
-- correlated subquery, even indirectly through a different alias — a
-- well-known restriction, worked around here the standard way: compute
-- the IDs to KEEP in a temporary table first (a plain SELECT has no
-- such restriction), then DELETE by a simple NOT IN against that
-- separate table instead of self-referencing at all.
CREATE TEMPORARY TABLE lucius_keep_ids AS
SELECT r.id FROM analysis_results r
WHERE NOT EXISTS (
    SELECT 1 FROM analysis_results r2
    WHERE r2.analysis_key = r.analysis_key AND r2.period_type = r.period_type
    AND (r2.analysis_version > r.analysis_version
         OR (r2.analysis_version = r.analysis_version AND r2.computed_at > r.computed_at)
         OR (r2.analysis_version = r.analysis_version AND r2.computed_at = r.computed_at AND r2.id > r.id))
);
DELETE FROM analysis_results WHERE id NOT IN (SELECT id FROM lucius_keep_ids);
DROP TEMPORARY TABLE lucius_keep_ids;

SET @lucius_old_key_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'analysis_results' AND INDEX_NAME = 'analysis_period_version'
);
SET @lucius_ddl = IF(@lucius_old_key_exists > 0, 'ALTER TABLE analysis_results DROP INDEX analysis_period_version', 'SELECT 1');
PREPARE lucius_stmt FROM @lucius_ddl;
EXECUTE lucius_stmt;
DEALLOCATE PREPARE lucius_stmt;

SET @lucius_new_key_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'analysis_results' AND INDEX_NAME = 'analysis_key_period_type'
);
SET @lucius_ddl = IF(@lucius_new_key_exists = 0, 'ALTER TABLE analysis_results ADD UNIQUE KEY analysis_key_period_type (analysis_key, period_type)', 'SELECT 1');
PREPARE lucius_stmt FROM @lucius_ddl;
EXECUTE lucius_stmt;
DEALLOCATE PREPARE lucius_stmt;

-- 3.5: EKG (Kardia) recordings — the GoDaddy-side slice of homeassistant/EKG_DESIGN.md.
-- Manual entry only for now: no PDF parser exists yet, so Ward reads the
-- Kardia PDF himself and fills in the fields, same as every other
-- WardStock form — this is the design doc's "confirmation screen"
-- without the automated extraction step ahead of it. The original PDF
-- is preserved as a BLOB in ecg_artifacts rather than the design doc's
-- filesystem storage_path — GoDaddy shared hosting has no established
-- non-webroot storage location in this project, and a DB-stored blob is
-- never directly URL-reachable by construction, which satisfies "no
-- direct unauthenticated file access" for free. Revisit if file size or
-- DB storage quota ever becomes a real constraint.
CREATE TABLE IF NOT EXISTS ecg_recordings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    recorded_at DATETIME NOT NULL,
    device_product VARCHAR(40) NOT NULL DEFAULT 'KardiaMobile',      -- KardiaMobile / KardiaMobile 6L
    lead_configuration VARCHAR(20) NOT NULL DEFAULT 'single_lead_i', -- single_lead_i / six_lead_limb / unknown
    duration_seconds DECIMAL(5,1) NULL,
    average_heart_rate_bpm SMALLINT UNSIGNED NULL,
    determination_code VARCHAR(40) NULL,      -- normalized code, see EKG_DESIGN.md "Determination codes"
    determination_text VARCHAR(120) NULL,     -- exact Kardia wording — kept distinct from the code, never overwritten by it
    signal_quality VARCHAR(20) NOT NULL DEFAULT 'unknown',   -- acceptable / poor / unreadable / unknown
    recording_reason VARCHAR(30) NOT NULL DEFAULT 'periodic_baseline',
    symptoms_present TINYINT(1) NOT NULL DEFAULT 0,
    symptoms_json TEXT NULL,                  -- [{code, intensity_0_10}, ...]
    activity_before VARCHAR(60) NULL,
    rest_minutes_before SMALLINT UNSIGNED NULL,
    related_incident_id INT NULL,             -- optional link into incidents, same idea as proposed_events.created_incident_id
    notes TEXT NULL,
    clinician_reviewed TINYINT(1) NOT NULL DEFAULT 0,
    clinician_interpretation TEXT NULL,
    clinician_reviewer_name VARCHAR(100) NULL,
    clinician_reviewed_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (related_incident_id) REFERENCES incidents(id),
    INDEX idx_ecg_recorded_at (recorded_at),
    INDEX idx_ecg_determination (determination_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ecg_artifacts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    recording_id INT NOT NULL,
    artifact_type VARCHAR(30) NOT NULL DEFAULT 'kardia_pdf_report',
    original_filename VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    byte_size INT UNSIGNED NOT NULL,
    sha256 CHAR(64) NOT NULL,                 -- computed before anything else touches the upload
    file_blob LONGBLOB NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (recording_id) REFERENCES ecg_recordings(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_ecg_recording_sha (recording_id, sha256)   -- duplicate-upload detection, EKG_DESIGN.md's artifact model
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

DROP PROCEDURE lucius_add_column_if_missing;

-- Version tracking (always safe to (re-)stamp — this is the current
-- state of the 3.x line as of this file's last update, Major.SQL = "3.5").
INSERT INTO app_settings (setting_key, setting_value) VALUES ('db_version', '3.5')
ON DUPLICATE KEY UPDATE setting_value = '3.5';
