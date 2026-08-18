-- Lucius — run after alter_add_system_status_reports.sql for this release.
-- Updates the stored schema version to match app_version.php (Major.SQL only).

INSERT INTO app_settings (setting_key, setting_value) VALUES ('db_version', '2.2')
ON DUPLICATE KEY UPDATE setting_value = '2.2';
