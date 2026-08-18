-- Lucius — add system_status_reports table (GoDaddy status page, PLAN.md §15)
-- Safe, additive.

CREATE TABLE IF NOT EXISTS system_status_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category VARCHAR(20) NOT NULL,               -- ha / nodered / analytics
    component VARCHAR(50) NOT NULL,              -- ha_core / oura_sync / godaddy_pull / bodycomp_import / ...
    last_run_at DATETIME NULL,
    last_status VARCHAR(20) NULL,                -- success / failed / unknown
    last_error TEXT NULL,
    detail TEXT NULL,                            -- free-form extra info (e.g. HA version), JSON-encoded if structured
    expected_frequency_minutes INT NULL,          -- used to compute "overdue"; NULL = not schedule-based
    reported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY category_component (category, component)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
