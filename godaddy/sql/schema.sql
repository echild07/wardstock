-- WardStock schema — fresh install (clean-slate rebuild)

CREATE TABLE IF NOT EXISTS incidents (
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

CREATE TABLE IF NOT EXISTS daily_logs (
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

CREATE TABLE IF NOT EXISTS medications (
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

INSERT INTO medications (name, dosage, med_type, cadence, frequency_days, start_date, sort_order) VALUES
    ('Amlodipine', '2.5mg', 'scheduled', 'daily', 1, '2025-08-12', 1),
    ('Aspirin', '81mg', 'scheduled', 'daily', 1, '2025-08-12', 2),
    ('Ezetimibe', '10mg', 'scheduled', 'daily', 1, '2025-08-12', 3),
    ('Rosuvastatin', '', 'scheduled', 'daily', 1, '2025-08-12', 4),
    ('Duloxetine', '20mg', 'scheduled', 'daily', 1, '2025-08-12', 5),
    ('Evolocumab (Repatha)', '140mg', 'scheduled', 'biweekly', 14, '2025-08-12', 6),
    ('Semaglutide (Wegovy)', '', 'scheduled', 'weekly', 7, '2025-08-12', 7),
    ('Nitroglycerin', '0.4mg', 'as_needed', 'as needed', 1, '2025-08-12', 8);

CREATE TABLE IF NOT EXISTS therapy_sessions (
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

CREATE TABLE IF NOT EXISTS therapy_schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_type VARCHAR(20) NOT NULL DEFAULT 'individual',
    start_date DATE NOT NULL,
    frequency_days INT NOT NULL DEFAULT 7,
    active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS oura_tokens (
    id INT PRIMARY KEY DEFAULT 1,
    access_token TEXT,
    refresh_token TEXT,
    expires_at DATETIME,
    last_success_at DATETIME NULL,     -- last time a real API call to Oura succeeded
    last_attempt_at DATETIME NULL,     -- last time a real API call was attempted (success or not)
    last_attempt_ok TINYINT(1) NULL,   -- whether that last attempt succeeded
    connected_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS app_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(50) NOT NULL UNIQUE,
    setting_value VARCHAR(255)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Kept in sync with upgrade_from_3.0.0.sql's own version-tracking stamp
-- at the bottom of that file — this baseline already includes every
-- table/seed that file's migrations add cumulatively (medication_dosage_
-- history, analysis_results, attention_snoozes, proposed_events,
-- blood_pressure_readings, preferred_timezone), so a fresh install and a
-- fully-migrated one should always agree on this number. (Found stale at
-- '3.1' during the preferred_timezone addition, Aug 2026 — this baseline
-- had already gained the 3.1 tables above without the stamp being
-- updated to match; fixed here.)
INSERT INTO app_settings (setting_key, setting_value) VALUES ('db_version', '3.3')
ON DUPLICATE KEY UPDATE setting_value = '3.3';

-- Preferred timezone (Ward, Aug 2026) — see settings.php / upgrade_from_3.0.0.sql's
-- own comment on this same key for why it exists.
INSERT IGNORE INTO app_settings (setting_key, setting_value) VALUES ('preferred_timezone', 'America/New_York');

CREATE TABLE IF NOT EXISTS app_user (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Logs every incoming call from the Home Assistant piece (Lucius project) —
-- one row per call, success or failure, to any of the api/*.php endpoints.
CREATE TABLE IF NOT EXISTS ha_sync_log (
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
CREATE TABLE IF NOT EXISTS system_status_reports (
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
-- needs. Versioned so a full recompute (PLAN.md §11 "Schedule &
-- caching") can land new rows without deleting old ones.
CREATE TABLE IF NOT EXISTS analysis_results (
    id INT AUTO_INCREMENT PRIMARY KEY,
    analysis_key VARCHAR(50) NOT NULL,
    period_type VARCHAR(10) NOT NULL,             -- daily / weekly / monthly / all
    period_start DATE NULL,
    period_end DATE NULL,
    analysis_version INT NOT NULL DEFAULT 1,
    result_json LONGTEXT NOT NULL,
    computed_at DATETIME NULL,
    pushed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY analysis_period_version (analysis_key, period_type, period_start, period_end, analysis_version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Attention/reminder snoozes (Fulgrim, feature list §3.2/3.2.0) — one row
-- per dismissed daily reminder. reminder_key is dated to the day the item
-- was DUE (e.g. "med_2026-08-21"), not the day it was snoozed, so the
-- snooze naturally expires the next calendar day without any cleanup job:
-- tomorrow's occurrence is simply a different key. See app/attention.php.
CREATE TABLE IF NOT EXISTS attention_snoozes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    reminder_key VARCHAR(80) NOT NULL,
    snoozed_on DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY reminder_snooze_day (reminder_key, snoozed_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- "Hypothetical" correlation events (Fulgrim, feature list §3.2.2) — the
-- not-yet-built Flux analysis engine will eventually write proposed
-- events here for Ward to confirm (becomes a real incident, feeds
-- wherewhen's future confidence) or deny (stays here as status='denied' —
-- Ward's own framing: "log it to be investigated" rather than delete it).
-- Page/queue is real and pushable now (GoDaddy-first build order); the
-- producer side is future HA work. See app/analysis.php.
CREATE TABLE IF NOT EXISTS proposed_events (
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
    FOREIGN KEY (created_incident_id) REFERENCES incidents(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Blood pressure readings (Fulgrim, feature list §1.2). A dedicated table
-- rather than columns on daily_logs — unlike weight (one value/day), a
-- real BP routine is typically 1-2+ timestamped readings/day (morning/
-- evening), and a future importer from an actual BP machine will want
-- per-reading granularity, not a collapsed daily value. Entry happens
-- inline from the Daily Log page for a given date (app/daily_form.php),
-- not a separate nav section. source distinguishes hand-entered rows from
-- a future import, same idea as ha_sync_log distinguishing call origins.
CREATE TABLE IF NOT EXISTS blood_pressure_readings (
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
