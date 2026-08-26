-- WardStock — clean reset (use only if you have no data worth keeping)
-- Drops everything and recreates with the current schema.
-- Run this once in phpMyAdmin's SQL tab.
--
-- Table list and column definitions are kept in exact sync with schema.sql
-- (the fresh-install baseline) — this file exists only to add the DROP
-- TABLE preamble schema.sql itself doesn't need, since schema.sql assumes
-- an empty database and reset_clean.sql assumes it might not be. If you
-- change one, change the other. (Aug 2026 — found badly out of sync: this
-- file was missing 7 tables entirely — analysis_results, attention_snoozes,
-- blood_pressure_readings, ecg_artifacts, ecg_recordings,
-- medication_dosage_history, proposed_events — and its incidents table was
-- a stale pre-"medical category" version missing stomach_sensation,
-- flu_symptoms_sensation, lethargy_sensation, related_medication_id, and
-- external_ref. Rebuilt directly from schema.sql's current definitions
-- rather than patched, since drift like this is exactly what happens when
-- two copies of the same table list are maintained by hand.)

-- Children before parents, so the drops themselves never fail on a live FK.
DROP TABLE IF EXISTS wardstock_ecg_artifacts;
DROP TABLE IF EXISTS wardstock_ecg_recordings;
DROP TABLE IF EXISTS wardstock_proposed_events;
DROP TABLE IF EXISTS wardstock_medication_dosage_history;
DROP TABLE IF EXISTS wardstock_blood_pressure_readings;
DROP TABLE IF EXISTS wardstock_attention_snoozes;
DROP TABLE IF EXISTS wardstock_analysis_results;
DROP TABLE IF EXISTS wardstock_incidents;
DROP TABLE IF EXISTS wardstock_daily_logs;
DROP TABLE IF EXISTS wardstock_medications;
DROP TABLE IF EXISTS wardstock_therapy_sessions;
DROP TABLE IF EXISTS wardstock_therapy_schedules;
DROP TABLE IF EXISTS wardstock_oura_tokens;
DROP TABLE IF EXISTS wardstock_app_settings;
DROP TABLE IF EXISTS wardstock_app_user;
DROP TABLE IF EXISTS wardstock_ha_sync_log;
DROP TABLE IF EXISTS wardstock_system_status_reports;

CREATE TABLE IF NOT EXISTS wardstock_incidents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category VARCHAR(20) NOT NULL DEFAULT 'anxiety',   -- anxiety / cardiac / medical
    occurred_at DATETIME NOT NULL,                     -- start time
    ended_at DATETIME NULL,                            -- end time
    trigger_context TEXT,
    thoughts_before TEXT,
    chest_sensation VARCHAR(20) DEFAULT 'none',        -- none / mild / moderate / severe
    arm_sensation VARCHAR(20) DEFAULT 'none',
    shoulder_sensation VARCHAR(20) DEFAULT 'none',
    headache_sensation VARCHAR(20) DEFAULT 'none',
    shaking VARCHAR(20) DEFAULT 'none',
    stomach_sensation VARCHAR(20) DEFAULT 'none',       -- medical category (side effects)
    flu_symptoms_sensation VARCHAR(20) DEFAULT 'none',
    lethargy_sensation VARCHAR(20) DEFAULT 'none',
    anxiety_intensity TINYINT,                         -- 0-10
    duration_minutes INT,
    nitroglycerin_taken TINYINT(1) NULL,                -- cardiac incidents only
    what_helped_recovery TEXT,
    differed_from_pattern VARCHAR(10) DEFAULT 'unknown', -- yes / no / unknown
    medical_evaluation VARCHAR(10) DEFAULT 'no',          -- yes / no
    medical_evaluation_notes TEXT,
    related_medication_id INT NULL,                     -- optional, medical category
    free_notes TEXT,
    external_ref VARCHAR(64) NULL UNIQUE,               -- e.g. medical-history YAML `id` slug (PLAN.md §19) —
                                                          -- idempotency key for machine-created incidents only,
                                                          -- never set/shown on the human incident_form.php
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wardstock_daily_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    log_date DATE NOT NULL,
    sleep_duration_hrs DECIMAL(4,2),
    sleep_efficiency TINYINT,
    resting_hr INT,
    hrv INT,
    weight DECIMAL(5,1),
    steps INT NULL,
    exercise_minutes INT NULL,
    standing_minutes INT NULL,
    activity_exertion TEXT,
    caffeine TEXT,
    caffeine_servings DECIMAL(3,1) NULL,
    alcohol TEXT,
    alcohol_drinks DECIMAL(3,1) NULL,
    medication_notes TEXT,
    medications_all_taken TINYINT(1) NULL,
    medications_taken_json TEXT NULL,
    mood_rating TINYINT,
    state_of_mind TINYINT NULL,        -- 1=Unpleasant .. 5=Enjoyed
    free_notes TEXT,
    night_waking_notes TEXT NULL,      -- why Ward woke / what he was thinking, if he woke in the night
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wardstock_medications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    dosage VARCHAR(50),
    med_type VARCHAR(12) NOT NULL DEFAULT 'scheduled',  -- scheduled / as_needed
    cadence VARCHAR(30) DEFAULT 'daily',                -- daily/weekly/biweekly/as needed (display label)
    frequency_days INT NOT NULL DEFAULT 1,              -- actual due-date math: (day - start_date) % frequency_days == 0
    start_date DATE NOT NULL,
    end_date DATE NULL,                                  -- NULL = still active
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Medication dosage-change history (Fulgrim/wherewhen, PLAN.md §11 #8) —
-- same medicine, different dosage, tracked over time so dosage changes
-- can be correlated against weight/HRV/sleep/mood/incidents.
CREATE TABLE IF NOT EXISTS wardstock_medication_dosage_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    medication_id INT NOT NULL,
    old_dosage VARCHAR(50) NULL,
    new_dosage VARCHAR(50) NOT NULL,
    changed_at DATE NOT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (medication_id) REFERENCES wardstock_medications(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Medications are NOT seeded here (Aug 2026 — removed a hardcoded personal
-- INSERT that lived in this file for years). Real bug that came from it,
-- live: the seed had Repatha/Wegovy at 2025-08-12 instead of their real
-- 2025-08-14 (Thursday) start, and restoring Ward's actual wardstock.sql
-- backup (which correctly has 2025-08-14) created duplicate rows instead
-- of updating the seeded one, since import_records()'s medication match
-- key is (name, start_date). README.md had already documented "go fix
-- Wegovy/Repatha's date after install" as a known gotcha for years without
-- the underlying seed ever being corrected — removing it outright, not
-- just fixing the date, so a wrong personal default can't silently ship
-- again. After a clean install, import your real medication list via
-- Import (import.php) or the godaddy_restore_from_file_flow.json Node-RED
-- flow — see godaddy/README.md's setup steps.

CREATE TABLE IF NOT EXISTS wardstock_therapy_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_date DATETIME NOT NULL,
    session_type VARCHAR(20) DEFAULT 'individual',   -- individual / couples / other
    summary TEXT,
    insights TEXT,
    homework TEXT,
    mood_before TINYINT,
    mood_after TINYINT,
    free_notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wardstock_therapy_schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_type VARCHAR(20) NOT NULL DEFAULT 'individual',
    start_date DATE NOT NULL,
    frequency_days INT NOT NULL DEFAULT 7,
    active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wardstock_oura_tokens (
    id INT PRIMARY KEY DEFAULT 1,
    access_token TEXT,
    refresh_token TEXT,
    expires_at DATETIME,
    last_success_at DATETIME NULL,     -- last time a real API call to Oura succeeded
    last_attempt_at DATETIME NULL,     -- last time a real API call was attempted (success or not)
    last_attempt_ok TINYINT(1) NULL,   -- whether that last attempt succeeded
    connected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wardstock_app_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(50) NOT NULL UNIQUE,
    setting_value VARCHAR(255)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Kept in sync with schema.sql's own db_version stamp — a fresh install
-- through either file should always agree on this number.
INSERT INTO wardstock_app_settings (setting_key, setting_value) VALUES ('db_version', '4.0')
ON DUPLICATE KEY UPDATE setting_value = '4.0';

-- Preferred timezone (Ward, Aug 2026) — see settings.php / upgrade_from_3.0.0.sql's
-- own comment on this same key for why it exists.
INSERT IGNORE INTO wardstock_app_settings (setting_key, setting_value) VALUES ('preferred_timezone', 'America/New_York');

CREATE TABLE IF NOT EXISTS wardstock_app_user (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Logs every incoming call from the Home Assistant piece (Lucius project) —
-- one row per call, success or failure, to any of the api/*.php endpoints.
CREATE TABLE IF NOT EXISTS wardstock_ha_sync_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    endpoint VARCHAR(30) NOT NULL,       -- oura_push / pull_manual_data / status
    called_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status_code VARCHAR(30) NOT NULL,    -- success / auth_invalid / malformed_request /
                                          -- validation_error / db_error / unknown_error
    detail TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- GoDaddy status page (Lucius project, PLAN.md §15) — one row per
-- (category, component), upserted every time the HA-side "Status
-- Heartbeat" flow reports in.
CREATE TABLE IF NOT EXISTS wardstock_system_status_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category VARCHAR(20) NOT NULL,               -- ha / nodered / analytics
    component VARCHAR(50) NOT NULL,              -- ha_core / oura_sync / godaddy_pull / bodycomp_import / ...
    last_run_at DATETIME NULL,
    last_status VARCHAR(20) NULL,                -- success / failed / unknown
    last_error TEXT NULL,
    detail TEXT NULL,
    expected_frequency_minutes INT NULL,
    reported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY category_component (category, component)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- wherewhen's pushed analyses/charts (Fulgrim, PLAN.md §11 "Where results
-- go"). One flexible table for all ~20 analyses rather than one table
-- per analysis — result_json holds whatever that analysis's chart page
-- needs. One row per (analysis_key, period_type) — analysis.php picks by
-- window-breadth preference (all > monthly > weekly > daily), not recency.
CREATE TABLE IF NOT EXISTS wardstock_analysis_results (
    id INT AUTO_INCREMENT PRIMARY KEY,
    analysis_key VARCHAR(50) NOT NULL,
    period_type VARCHAR(10) NOT NULL,             -- daily / weekly / monthly / all
    period_start DATE NULL,
    period_end DATE NULL,
    analysis_version INT NOT NULL DEFAULT 1,
    result_json LONGTEXT NOT NULL,
    computed_at DATETIME NULL,
    pushed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY analysis_key_period_type (analysis_key, period_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Attention/reminder snoozes (Fulgrim, feature list §3.2/3.2.0) — one row
-- per dismissed daily reminder. reminder_key is dated to the day the item
-- was DUE (e.g. "med_2026-08-21"), not the day it was snoozed, so the
-- snooze naturally expires the next calendar day without any cleanup job.
-- See app/attention.php.
CREATE TABLE IF NOT EXISTS wardstock_attention_snoozes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reminder_key VARCHAR(80) NOT NULL,
    snoozed_on DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY reminder_snooze_day (reminder_key, snoozed_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- "Hypothetical" correlation events (Fulgrim, feature list §3.2.2) — the
-- not-yet-built Flux analysis engine will eventually write proposed
-- events here for Ward to confirm (becomes a real incident) or deny
-- (stays here as status='denied'). See app/analysis.php.
CREATE TABLE IF NOT EXISTS wardstock_proposed_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    analysis_key VARCHAR(50) NOT NULL,
    proposed_at DATETIME NOT NULL,
    suggested_occurred_at DATETIME NULL,
    suggested_category VARCHAR(20) NULL,
    description TEXT NOT NULL,
    confidence DECIMAL(4,3) NULL,
    result_json LONGTEXT NULL,
    status VARCHAR(10) NOT NULL DEFAULT 'pending',  -- pending / confirmed / denied
    reviewed_at DATETIME NULL,
    review_notes TEXT NULL,
    created_incident_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_incident_id) REFERENCES wardstock_incidents(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Blood pressure readings (Fulgrim, feature list §1.2). A dedicated table
-- rather than columns on daily_logs — a real BP routine is typically 1-2+
-- timestamped readings/day, not a collapsed daily value. Entry happens
-- inline from the Daily Log page (app/daily_form.php).
CREATE TABLE IF NOT EXISTS wardstock_blood_pressure_readings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reading_at DATETIME NOT NULL,
    systolic SMALLINT UNSIGNED NOT NULL,
    diastolic SMALLINT UNSIGNED NOT NULL,
    pulse SMALLINT UNSIGNED NULL,
    position VARCHAR(20) NULL,                      -- seated / standing / lying — optional, affects reading accuracy
    notes TEXT NULL,
    source VARCHAR(20) NOT NULL DEFAULT 'manual',    -- manual / import
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_reading_at (reading_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- EKG (Kardia) recordings, Aug 2026 — GoDaddy-side slice of
-- homeassistant/EKG_DESIGN.md. Manual entry only for now — no PDF parser
-- exists yet, so Ward reads the Kardia PDF himself and fills in the
-- fields, same as every other WardStock form.
CREATE TABLE IF NOT EXISTS wardstock_ecg_recordings (
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
    related_incident_id INT NULL,             -- optional link into wardstock_incidents, same idea as proposed_events.created_incident_id
    notes TEXT NULL,
    clinician_reviewed TINYINT(1) NOT NULL DEFAULT 0,
    clinician_interpretation TEXT NULL,
    clinician_reviewer_name VARCHAR(100) NULL,
    clinician_reviewed_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (related_incident_id) REFERENCES wardstock_incidents(id),
    INDEX idx_ecg_recorded_at (recorded_at),
    INDEX idx_ecg_determination (determination_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS wardstock_ecg_artifacts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    recording_id INT NOT NULL,
    artifact_type VARCHAR(30) NOT NULL DEFAULT 'kardia_pdf_report',
    original_filename VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    byte_size INT UNSIGNED NOT NULL,
    sha256 CHAR(64) NOT NULL,                 -- computed before anything else touches the upload
    file_blob LONGBLOB NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (recording_id) REFERENCES wardstock_ecg_recordings(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_ecg_recording_sha (recording_id, sha256)   -- duplicate-upload detection, EKG_DESIGN.md's artifact model
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
