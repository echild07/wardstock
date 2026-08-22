<?php
// WardStock version: Major.SQLRevision.CodeRevision
//
// - APP_VERSION_MAJOR: bump only for a genuinely major overhaul. Resets
//   SQL and CODE to 0. Bumped to 2 (from 1, "Sidroh") when the project
//   expanded from a single GoDaddy-hosted app into a two-piece project —
//   GoDaddy (this app) + a Home Assistant sync/analytics piece (see
//   /homeassistant at the project root). Same app, same version scheme,
//   now under a project-wide codename covering both pieces.
//   Bumped to 3 ("Fulgrim", from 2 "Lucius") for the wherewhen
//   correlation-analysis engine — new incident category, medication
//   dosage history, night-waking capture, ~20 analysis charts, and the
//   new "Where When" nav section. See homeassistant/PLAN.md §11/§18-20.
// - APP_VERSION_SQL: bump ONLY when a release actually changes something
//   in sql/ (a new/changed schema.sql, a new alter_*.sql). Resets CODE
//   to 0. This is the ONLY number the database needs to know about —
//   db_version stores just "Major.SQL", never the full three-part
//   version, since code-only changes never require running anything
//   in phpMyAdmin.
// - APP_VERSION_CODE: bump for every release that does NOT touch sql/.
//   A release that DOES touch sql/ bumps SQL instead of this (and
//   resets this to 0) — never bump both in the same release.
//
// debug.php compares the database's db_version against
// APP_VERSION_SCHEMA (Major.SQL only) — never the full APP_VERSION —
// so a code-only release never shows as "out of sync" just because no
// migration was ever shipped for it to run.

define('APP_VERSION_NAME', 'Fulgrim');
define('APP_VERSION_MAJOR', 3);
define('APP_VERSION_SQL', 5);
define('APP_VERSION_CODE', 4);
define('APP_VERSION_SCHEMA', sprintf('%d.%d', APP_VERSION_MAJOR, APP_VERSION_SQL));
define('APP_VERSION', sprintf('%d.%d.%d', APP_VERSION_MAJOR, APP_VERSION_SQL, APP_VERSION_CODE));
