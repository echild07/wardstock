-- WardStock — cumulative upgrade script, 4.0.0 -> current (4.x line, "Drew")
--
-- Run this ONCE in phpMyAdmin's SQL tab for any 4.x install. Safe to run
-- from any point in the 4.x line, same convention as upgrade_from_3.0.0.sql.
--
-- 3.x -> 4.x itself needs NO schema change. This major bump (Aug 2026) is a
-- version-ALIGNMENT bump, not a feature overhaul — Ward's decision to give
-- every LeeWard product (WardStock, wherewhen, standwhy) the same major
-- number and codename going forward, tracked centrally in
-- leeward/VERSIONS.md. WardStock's own schema is unchanged from 3.5 —
-- this file exists only to stamp db_version so debug.php's version check
-- doesn't flag a false mismatch against the new APP_VERSION_SCHEMA ("4.0").
--
-- Coming from the 3.x line? Run upgrade_from_3.0.0.sql first if you haven't
-- already (any 3.x-line schema change lives there, not here) — this file
-- only covers what's new for 4.x, which for now is nothing but the stamp.
--
-- This file is a living document, not a one-time script — every future
-- 4.x.x release that changes sql/ gets ITS migration appended below,
-- version stamp at the bottom updated to match. A new file only appears
-- at the next major version bump.

-- Version tracking (always safe to (re-)stamp — this is the current
-- state of the 4.x line as of this file's last update, Major.SQL = "4.0").
INSERT INTO app_settings (setting_key, setting_value) VALUES ('db_version', '4.0')
ON DUPLICATE KEY UPDATE setting_value = '4.0';
