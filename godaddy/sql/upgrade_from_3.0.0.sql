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

DROP PROCEDURE lucius_add_column_if_missing;

-- Version tracking (always safe to (re-)stamp — this is the current
-- state of the 3.x line as of this file's last update, Major.SQL = "3.1").
INSERT INTO app_settings (setting_key, setting_value) VALUES ('db_version', '3.1')
ON DUPLICATE KEY UPDATE setting_value = '3.1';
