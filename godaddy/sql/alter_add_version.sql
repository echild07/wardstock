-- WardStock — add version tracking (one-time, for installs that predate this feature entirely)
-- Safe, additive. Sets the database's recorded schema revision to match
-- the current release. After this, a future set_version.sql only appears
-- in a release's sql/ folder when sql/ actually changed that release —
-- if you don't see one, there's nothing new to run here.

INSERT INTO app_settings (setting_key, setting_value) VALUES ('db_version', '2.0')
ON DUPLICATE KEY UPDATE setting_value = '2.0';
