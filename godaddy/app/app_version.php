<?php
// WardStock version: Major.Data.Code  (Data = storage-format revision)
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
//   Bumped to 4 ("Drew", from 3 "Fulgrim") — NOT a WardStock feature
//   overhaul this time. Ward's decision (Aug 2026): every LeeWard product
//   (WardStock, wherewhen, standwhy) now shares the same MAJOR number and
//   the same codename at all times, tracked centrally in
//   leeward/VERSIONS.md — "Drew" names this company-wide release wave
//   across every product, not just WardStock's own history. Each
//   product's Data/Code revisions stay independent; only MAJOR and the
//   codename are shared. No schema change came with this bump — see
//   sql/upgrade_from_4.0.0.sql, which exists only to stamp db_version.
//   Codename order decided ahead of time (Ward, Aug 2026), so the next
//   several major bumps already have their name picked before whatever
//   triggers each one is even known: 5 = "Aidan", 6 = "Zoe", 7 = "Bean",
//   8 = "Scout". Use the next unused name in this list when MAJOR next
//   bumps, for every product at once — don't invent a new one, and don't
//   bump this product's major number alone without also updating
//   leeward/VERSIONS.md and the other products.
//   Still 4.0.0 (Ward, Aug 2026): the table-prefix convention
//   (leeward/STANDARDS.md §5 — every table renamed `wardstock_{tablename}`,
//   so multiple LeeWard products can eventually share one MySQL database
//   without name collisions) is folded INTO the 4.0.0 upgrade path rather
//   than bumping Data to 1 — sql/upgrade_from_4.0.0.sql's job is to
//   prepare a database FOR 4.0.0, renames included. The version number
//   (and its git tag) only advances at actual deploy time, not at every
//   git push — several rounds of local work can accumulate under one
//   not-yet-deployed number. Once 4.0.0 actually ships live, it's final;
//   the next round of work bumps from there.
// - APP_VERSION_DATA (also APP_VERSION_SQL): bump ONLY when persisted
//   storage format changes — sql/, SQLite, Influx measurement/tag/field
//   layout, or another restore contract. Resets CODE to 0. The database
//   stores "Major.Data" only (db_version), never the full three-part
//   version, since code-only changes never require phpMyAdmin.
// - APP_VERSION_CODE: bump on every code push/release that does NOT
//   change storage. A release that DOES change storage bumps Data
//   instead of this (and resets this to 0) — never bump both in the
//   same release.
//
// debug.php compares the database's db_version against
// APP_VERSION_SCHEMA (Major.Data only) — never the full APP_VERSION —
// so a code-only release never shows as "out of sync" just because no
// migration was ever shipped for it to run.

define('APP_VERSION_NAME', 'Drew');
define('APP_VERSION_MAJOR', 4);
define('APP_VERSION_DATA', 0);
define('APP_VERSION_SQL', APP_VERSION_DATA);
define('APP_VERSION_CODE', 0);
define('APP_VERSION_SCHEMA', sprintf('%d.%d', APP_VERSION_MAJOR, APP_VERSION_DATA));
define('APP_VERSION', sprintf('%d.%d.%d', APP_VERSION_MAJOR, APP_VERSION_DATA, APP_VERSION_CODE));
