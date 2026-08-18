-- WardStock schema — fresh install (clean-slate rebuild)

CREATE TABLE IF NOT EXISTS incidents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category VARCHAR(10) NOT NULL DEFAULT 'anxiety',   -- anxiety / cardiac
    occurred_at DATETIME NOT NULL,                     -- start time
    ended_at DATETIME NULL,                            -- end time
    trigger_context TEXT,
    thoughts_before TEXT,
    chest_sensation VARCHAR(20) DEFAULT 'none',        -- none / mild / moderate / severe
    arm_sensation VARCHAR(20) DEFAULT 'none',
    shoulder_sensation VARCHAR(20) DEFAULT 'none',
    headache_sensation VARCHAR(20) DEFAULT 'none',
    shaking VARCHAR(20) DEFAULT 'none',
    anxiety_intensity TINYINT,                         -- 0-10
    duration_minutes INT,
    nitroglycerin_taken TINYINT(1) NULL,                -- cardiac incidents only
    what_helped_recovery TEXT,
    differed_from_pattern VARCHAR(10) DEFAULT 'unknown', -- yes / no / unknown
    medical_evaluation VARCHAR(10) DEFAULT 'no',          -- yes / no
    medical_evaluation_notes TEXT,
    free_notes TEXT,
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

INSERT INTO app_settings (setting_key, setting_value) VALUES ('db_version', '2.1')
ON DUPLICATE KEY UPDATE setting_value = '2.1';

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
