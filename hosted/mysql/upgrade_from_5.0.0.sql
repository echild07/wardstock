-- WardStock — cumulative upgrade script, 5.0.0 -> current (5.x line, "Aidan")
--
-- Run this ONCE in phpMyAdmin's SQL tab for any 5.x install. Safe to run
-- from any point in the 5.x line, same convention as upgrade_from_4.0.0.sql.
--
-- Stamp-only: the Major 5 "Aidan" bump is a version-alignment bump, same
-- shape as 4.0.0 "Drew" — every LeeWard product moved to the next
-- pre-decided codename at once (leeward/VERSIONS.md), Data/Code reset to
-- 0. The real work bundled into this line is a branding cleanup, not a
-- schema change: WardStock's own /share filenames, SQLite DB names, and
-- Node-RED tab labels had the retired "Lucius" release codename renamed
-- to "WardStock" (see RETROSPECTIVE.md Phase 11) — nothing in this MySQL
-- database's table structure or names changed. This file exists only so
-- a database still stamped "4.0" doesn't false-positive against
-- debug.php's version-mismatch check once the app itself reports 5.0.0.
--
-- Coming from the 4.x line? Run upgrade_from_4.0.0.sql first if you
-- haven't already (any 4.x-line schema change lives there, not here) —
-- this file only covers what's new for 5.x, which as of this writing is
-- nothing structural.

INSERT INTO wardstock_app_settings (setting_key, setting_value) VALUES ('db_version', '5.0')
ON DUPLICATE KEY UPDATE setting_value = '5.0';
