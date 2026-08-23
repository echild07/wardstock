<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/auth.php';

// Demo mode (godaddy/demo/, Aug 2026 — "walk through the app without live
// data"): same connection function every page already calls, just pointed
// at a separate, fully isolated demo database when $_SESSION['demo_mode']
// is set. No new code paths for pages to know about — is_demo_mode() is
// the only new thing they'd ever need, and most never will. Real writes
// ARE allowed in demo mode (this is a real PDO connection, not a no-op
// wrapper — deliberately simple over clever, see demo/README.md) since
// it's a completely separate database from the live one; demo/
// generate_demo_data.php is the reset mechanism for stale/messy demo data,
// not write-blocking at the connection layer.
function get_db() {
    static $pdo = null;
    static $pdoIsDemo = null;
    $demo = is_demo_mode() && defined('DEMO_DB_NAME') && DEMO_DB_NAME !== '';
    if ($pdo === null || $pdoIsDemo !== $demo) {
        if ($demo) {
            $dsn = 'mysql:host=' . DEMO_DB_HOST . ';dbname=' . DEMO_DB_NAME . ';charset=utf8mb4';
            $pdo = new PDO($dsn, DEMO_DB_USER, DEMO_DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } else {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        }
        $pdoIsDemo = $demo;
    }
    return $pdo;
}

// "What is 'now'/'today', for real" — GoDaddy's PHP has no timezone ever
// set (date_default_timezone_set() is never called anywhere in this
// codebase — confirmed by grep, not assumed), so bare date()/new
// DateTime('now')/strtotime('today') all silently use PHP's own default,
// which is UTC on GoDaddy shared hosting. Real bug, Aug 2026 (Ward: "on
// the front page it shows Friday the 21st, when it is 9:27pm here" —
// past midnight UTC, still evening the day before in Eastern). Anywhere
// "today"/"now" is used to decide WHICH DATA to show or query (not just
// formatting an already-known past timestamp for display — that's a
// separate, client-side-JS concern, see analysis.php/status.php's own
// js-local-time handling) needs Ward's actual calendar day, which needs
// his preferred_timezone (settings.php/app_settings — same setting the
// wherewhen engine's day-grouping already uses, PLAN.md §11), not
// whatever the server happens to default to. Falls back to
// America/New_York if the setting's never been saved, matching every
// other consumer of this same setting.
function app_now($pdo) {
    $tz = get_setting($pdo, 'preferred_timezone') ?: 'America/New_York';
    try {
        return new DateTime('now', new DateTimeZone($tz));
    } catch (Exception $e) {
        return new DateTime('now', new DateTimeZone('America/New_York'));
    }
}
function app_today($pdo) {
    return app_now($pdo)->format('Y-m-d');
}

// Human-readable medication changes (started / ended / dosage changed) whose
// start_date or end_date falls within [$rangeStart, $rangeEnd] (inclusive).
// Shared by incident_form.php ("Medication changes, last 7 days") and
// therapy_form.php ("Medication changes, since last session") — same
// underlying question, "what changed in this window," just different
// windows (Aug 2026).
//
// A "dosage change" is two adjacent eras of the SAME medication where the
// old one's end_date is exactly the day before the new one's start_date —
// the pattern medication_form.php's "Start a new dosage" flow always
// produces. Reporting those as an unrelated "Started X" + "Ended X" pair
// reads as if the medication was discontinued, which is misleading (Ward,
// Aug 2026, first found in the incident form: "we didn't end Duloxetine,
// we changed the dosage"). Detected by direct lookup per row rather than by
// pairing up rows already fetched for the window, so it's correct even
// right at the window's edge (e.g. the old row's end_date is in range but
// the new row's start_date — exactly one day later — has just rolled past
// $rangeEnd).
function medication_change_lines($pdo, $rangeStart, $rangeEnd) {
    $stmt = $pdo->prepare('SELECT * FROM wardstock_medications
        WHERE (start_date BETWEEN ? AND ?) OR (end_date BETWEEN ? AND ?)
        ORDER BY COALESCE(end_date, start_date) DESC');
    $stmt->execute([$rangeStart, $rangeEnd, $rangeStart, $rangeEnd]);
    $medChanges = $stmt->fetchAll();

    $lines = [];
    foreach ($medChanges as $m) {
        if ($m['start_date'] >= $rangeStart && $m['start_date'] <= $rangeEnd) {
            $prevStmt = $pdo->prepare('SELECT dosage FROM wardstock_medications WHERE name = ? AND end_date = DATE_SUB(?, INTERVAL 1 DAY)');
            $prevStmt->execute([$m['name'], $m['start_date']]);
            $prevDosage = $prevStmt->fetchColumn();
            if ($prevDosage !== false) {
                $lines[] = 'Dosage changed: ' . $m['name'] . ' ' . ($prevDosage ?: 'unset') . ' → ' . ($m['dosage'] ?: 'unset') . ' on ' . date('M j', strtotime($m['start_date']));
            } else {
                $lines[] = 'Started ' . $m['name'] . ($m['dosage'] ? ' (' . $m['dosage'] . ')' : '') . ' on ' . date('M j', strtotime($m['start_date']));
            }
        }
        if ($m['end_date'] && $m['end_date'] >= $rangeStart && $m['end_date'] <= $rangeEnd) {
            $nextStmt = $pdo->prepare('SELECT id FROM wardstock_medications WHERE name = ? AND start_date = DATE_ADD(?, INTERVAL 1 DAY)');
            $nextStmt->execute([$m['name'], $m['end_date']]);
            if (!$nextStmt->fetchColumn()) {
                // Only a genuine discontinuation gets an "Ended" line — if a
                // successor era starts the very next day, that pairing is
                // already reported once above as "Dosage changed," from the
                // successor row's own start_date branch.
                $lines[] = 'Ended ' . $m['name'] . ' on ' . date('M j', strtotime($m['end_date']));
            }
        }
    }
    return $lines;
}

// Is a scheduled medication actually due on a given date? Checks both that
// the prescription is currently active (start/end date) AND that this
// specific day lines up with its recurrence (daily/weekly/biweekly/etc via
// frequency_days) — the same due-date math used for therapy_schedules.
// Without this second check, a weekly or biweekly medication would show as
// due (and could be marked "taken" via All Taken) on every single day.
function medication_due_on($med, $day) {
    if ($med['start_date'] > $day) return false;
    if ($med['end_date'] !== null && $med['end_date'] < $day) return false;
    $freq = max(1, (int)($med['frequency_days'] ?? 1));
    $diffDays = (strtotime($day) - strtotime($med['start_date'])) / 86400;
    return $diffDays >= 0 && $diffDays % $freq == 0;
}

// Format decimal hours (as stored in sleep_duration_hrs) as "7h 23m" rather
// than a raw decimal like "7.38" — matches how people actually think about
// sleep duration.
function fmt_hours_minutes($decimalHours) {
    if ($decimalHours === null) return null;
    $h = (int)floor((float)$decimalHours);
    $m = (int)round(((float)$decimalHours - $h) * 60);
    if ($m === 60) { $h++; $m = 0; }
    return $h . 'h ' . $m . 'm';
}

// Logs a single call to any api/*.php endpoint — success or failure, always.
// Deliberately called on every path through every endpoint (see api/ files)
// so ha_sync_log has a row for every call HA ever made, not just failures.
// status_code is one of: success, auth_invalid, malformed_request,
// validation_error, db_error, unknown_error.
function log_ha_sync($pdo, $endpoint, $statusCode, $detail = null) {
    $stmt = $pdo->prepare('INSERT INTO wardstock_ha_sync_log (endpoint, status_code, detail) VALUES (?, ?, ?)');
    $stmt->execute([$endpoint, $statusCode, $detail]);
}

// Body Composition Import (Lucius project, HA piece — PLAN.md §14): sets
// daily_logs.weight for a date ONLY if it's currently unset. Never
// overwrites a manual entry made before or after the scale data arrives,
// and makes re-importing the same historical rows safe by default (the
// scale app's export has a poor date-range picker, so re-exports routinely
// contain days already imported — a day that already has a weight, from
// any source, is always a no-op here). Returns true if a value was
// written, false if it was left alone (already set).
function push_weight_if_unset($pdo, $date, $weightLb) {
    $existing = $pdo->prepare('SELECT id, weight FROM wardstock_daily_logs WHERE log_date = ?');
    $existing->execute([$date]);
    $row = $existing->fetch();

    if ($row) {
        if ($row['weight'] !== null) {
            return false;
        }
        $stmt = $pdo->prepare('UPDATE wardstock_daily_logs SET weight = ? WHERE id = ?');
        $stmt->execute([$weightLb, $row['id']]);
        return true;
    }

    $stmt = $pdo->prepare('INSERT INTO wardstock_daily_logs (log_date, weight) VALUES (?, ?)');
    $stmt->execute([$date, $weightLb]);
    return true;
}

// Overdue = elapsed time since last_run_at exceeds the component's own
// expected cadence plus a grace buffer. The buffer scales with the
// cadence itself (min 15 minutes) rather than a single fixed ratio —
// PLAN.md §15 only gave illustrative examples ("~4.5h" for a 4h
// schedule, "~20min" for a 15min one), not an exact formula, so this is
// a deliberate, reasonable approximation, not a value Ward specified.
// Originally lived only in status.php; moved here (Fulgrim, attention
// reminders) so index.php's Oura-stale reminder uses the exact same
// staleness logic status.php already displays, instead of a second
// copy that could quietly drift from it.
function overdue_info($row) {
    if (!$row['expected_frequency_minutes'] || !$row['last_run_at']) {
        return null; // not schedule-based, or never reported — nothing to compute
    }
    $expected = (int)$row['expected_frequency_minutes'];
    $buffer = max(15, (int)round($expected * 0.25));
    $elapsedMin = (time() - strtotime($row['last_run_at'])) / 60;
    return [
        'elapsed_min' => $elapsedMin,
        'is_overdue' => $elapsedMin > ($expected + $buffer),
    ];
}

// Blood pressure category, AHA's standard consumer thresholds (Fulgrim,
// feature list §1.2) — display/color coding only, same "not medical
// advice, more for fun with data" framing Ward gave the whole feature.
// Shared by daily_form.php's reading list, index.php's dashboard pill,
// and blood_pressure_trend.php.
function bp_category($systolic, $diastolic) {
    if ($systolic === null || $diastolic === null) return null;
    $s = (float)$systolic; $d = (float)$diastolic;
    if ($s > 180 || $d > 120) return 'crisis';
    if ($s >= 140 || $d >= 90) return 'stage2';
    if ($s >= 130 || $d >= 80) return 'stage1';
    if ($s >= 120) return 'elevated';
    return 'normal';
}
function bp_category_label($cat) {
    $labels = ['normal' => 'Normal', 'elevated' => 'Elevated', 'stage1' => 'High (Stage 1)', 'stage2' => 'High (Stage 2)', 'crisis' => 'Very high'];
    return $labels[$cat] ?? 'Unknown';
}
// Reuses the app's existing 3-tier pill palette (good/neutral/bad) rather
// than inventing a 5th color for a 5-category scale.
function bp_category_pill_class($cat) {
    $classes = ['normal' => 'pill-good', 'elevated' => 'pill-neutral', 'stage1' => 'pill-neutral', 'stage2' => 'pill-bad', 'crisis' => 'pill-bad'];
    return $classes[$cat] ?? 'pill-zero';
}
// Reuses the existing severity-tag palette (incidents' lvl-mild/moderate/severe).
function bp_category_tag_class($cat) {
    $classes = ['normal' => 'lvl-mild', 'elevated' => 'lvl-moderate', 'stage1' => 'lvl-moderate', 'stage2' => 'lvl-severe', 'crisis' => 'lvl-severe'];
    return $classes[$cat] ?? '';
}

// Was duplicated separately in export.php and debug.php — consolidated
// here since more files (the new api/*.php endpoints) now need it too.
function get_setting($pdo, $key) {
    $stmt = $pdo->prepare('SELECT setting_value FROM wardstock_app_settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row ? $row['setting_value'] : null;
}
function set_setting($pdo, $key, $value) {
    $stmt = $pdo->prepare('INSERT INTO wardstock_app_settings (setting_key, setting_value) VALUES (?, ?)
                            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
    $stmt->execute([$key, $value]);
}

// Builds the same record set export.php has always produced — shared so
// api/pull_manual_data.php (the Lucius project's HA piece) returns
// identical shape/content to a human-triggered export, not a second
// parallel implementation that could quietly drift from this one.
// $types is a subset of ['incidents', 'daily_logs', 'therapy_sessions',
// 'medications']. Note: export.php's own UI never requests 'medications'
// (a deliberate original decision — the human export leaves medication
// history out, since each daily_log record already carries resolved
// medication names). pull_manual_data.php DOES request it — different
// purpose, disaster-recovery completeness rather than a casual export.
function build_export_records($pdo, $since, $types) {
    $data = ['records' => []];

    $medNames = [];
    foreach ($pdo->query('SELECT id, name FROM wardstock_medications')->fetchAll() as $m) {
        $medNames[(int)$m['id']] = $m['name'];
    }
    $data['state_of_mind_scale'] = [1 => 'Unpleasant', 2 => 'Slightly Unpleasant', 3 => 'Neutral', 4 => 'Slightly Enjoyed', 5 => 'Enjoyed'];

    if (in_array('medications', $types)) {
        // Always the full table, regardless of $since — medications has no
        // updated_at column, and start_date/end_date don't reliably mean
        // "changed recently" (e.g. a dosage typo correction wouldn't move
        // either date). The table is small; pulling it complete every time
        // is simpler and correct, where a since-filter here would silently
        // miss real changes.
        foreach ($pdo->query('SELECT * FROM wardstock_medications ORDER BY sort_order, name')->fetchAll() as $row) {
            $row['record_type'] = 'medication';
            $data['records'][] = $row;
        }
    }

    if (in_array('incidents', $types)) {
        $sql = 'SELECT * FROM wardstock_incidents' . ($since ? ' WHERE updated_at > :since' : '') . ' ORDER BY occurred_at';
        $stmt = $pdo->prepare($sql);
        if ($since) $stmt->execute(['since' => $since]); else $stmt->execute();
        foreach ($stmt->fetchAll() as $row) { $row['record_type'] = 'incident'; $data['records'][] = $row; }
    }
    if (in_array('daily_logs', $types)) {
        $sql = 'SELECT * FROM wardstock_daily_logs' . ($since ? ' WHERE updated_at > :since' : '') . ' ORDER BY log_date';
        $stmt = $pdo->prepare($sql);
        if ($since) $stmt->execute(['since' => $since]); else $stmt->execute();
        foreach ($stmt->fetchAll() as $row) {
            $row['record_type'] = 'daily_log';
            $takenIds = [];
            if ($row['medications_taken_json']) {
                $decoded = json_decode($row['medications_taken_json'], true);
                if (is_array($decoded)) $takenIds = array_map('intval', $decoded);
            }
            $row['medications_taken'] = array_values(array_map(fn($id) => $medNames[$id] ?? "medication #$id", $takenIds));
            unset($row['medications_taken_json']);
            $data['records'][] = $row;
        }
    }
    if (in_array('therapy_sessions', $types)) {
        $sql = 'SELECT * FROM wardstock_therapy_sessions' . ($since ? ' WHERE updated_at > :since' : '') . ' ORDER BY session_date';
        $stmt = $pdo->prepare($sql);
        if ($since) $stmt->execute(['since' => $since]); else $stmt->execute();
        foreach ($stmt->fetchAll() as $row) { $row['record_type'] = 'therapy_session'; $data['records'][] = $row; }
    }

    if (in_array('ecg_recordings', $types)) {
        // Metadata only — the PDF itself (ecg_artifacts.file_blob) is
        // deliberately never included here. This mirrors EKG_DESIGN.md's
        // own artifact-vs-summary split (artifacts need authenticated,
        // audited download; only the recording summary rides along in
        // bulk sync) even though this app doesn't yet have the doc's full
        // artifact download audit trail — no reason to make the 15-minute
        // HA pull carry binary PDFs regardless.
        //
        // Own try/catch, unlike every other block above — ecg_recordings
        // is new (Aug 2026) and this whole function otherwise fails as one
        // unit (a thrown exception here would take incidents/daily_logs/
        // therapy_sessions down with it). On any install where the SQL
        // migration hasn't been run yet, skip EKG rather than lose the
        // rest of a disaster-recovery pull to a table that doesn't exist.
        try {
            $sql = 'SELECT * FROM wardstock_ecg_recordings' . ($since ? ' WHERE updated_at > :since' : '') . ' ORDER BY recorded_at';
            $stmt = $pdo->prepare($sql);
            if ($since) $stmt->execute(['since' => $since]); else $stmt->execute();
            foreach ($stmt->fetchAll() as $row) { $row['record_type'] = 'ecg_recording'; $data['records'][] = $row; }
        } catch (Throwable $e) {
            error_log('build_export_records: ecg_recordings skipped — ' . $e->getMessage());
        }
    }

    if (in_array('medication_dosage_history', $types)) {
        // Insert-only table (medication_form.php only ever INSERTs here,
        // never UPDATEs), so unlike medications a since-filter on
        // created_at is safe — no risk of missing an in-place edit that
        // doesn't touch created_at. medication_name is denormalized via
        // the join specifically because medication_id isn't portable
        // across a reimport (see import.php's handling of this type) —
        // medications get re-matched/re-inserted by (name, start_date),
        // which can assign a different id on the target database.
        $sql = 'SELECT h.*, m.name AS medication_name FROM wardstock_medication_dosage_history h
                JOIN wardstock_medications m ON m.id = h.medication_id'
                . ($since ? ' WHERE h.created_at > :since' : '') . ' ORDER BY h.changed_at';
        $stmt = $pdo->prepare($sql);
        if ($since) $stmt->execute(['since' => $since]); else $stmt->execute();
        foreach ($stmt->fetchAll() as $row) {
            unset($row['medication_id']);
            $row['record_type'] = 'medication_dosage_history';
            $data['records'][] = $row;
        }
    }

    return $data;
}

// The other direction from build_export_records() — takes a "records"
// array in the exact same shape (whatever record_type-tagged rows a
// pull_manual_data.php/export.php response contains) and upserts each one
// back into its table. Originally lived entirely inside import.php (the
// human file-upload page); extracted here (Aug 2026) so
// api/bulk_restore.php — a new token-authenticated endpoint for Home
// Assistant's own restore-from-local-backup flow — can call the exact
// same merge-safe logic instead of a second implementation that could
// quietly drift from it. import.php now just handles the file upload and
// calls this.
//
// Owns its own transaction: the whole batch commits together or not at
// all, same guarantee import.php always gave ("nothing was saved" on any
// failure partway through). Throws on failure after rolling back — the
// caller decides how to present that (import.php shows it as a page
// error; bulk_restore.php returns it as a 500 JSON response).
function import_records($pdo, array $records) {
    $medLookup = [];
    foreach ($pdo->query('SELECT id, name FROM wardstock_medications')->fetchAll() as $m) {
        $medLookup[$m['name']] = (int)$m['id'];
    }

    $counts = [
        'incident' => ['inserted' => 0, 'skipped' => 0],
        'daily_log' => ['inserted' => 0, 'updated' => 0, 'skipped' => 0],
        'therapy_session' => ['inserted' => 0, 'updated' => 0, 'skipped' => 0],
        'medication' => ['inserted' => 0, 'updated' => 0, 'skipped' => 0],
        'medication_dosage_history' => ['inserted' => 0, 'skipped' => 0],
        'ecg_recording' => ['inserted' => 0, 'updated' => 0, 'skipped' => 0],
    ];
    $unmatchedMeds = [];

    $pdo->beginTransaction();
    try {
        foreach ($records as $rec) {
            $type = $rec['record_type'] ?? null;

            if ($type === 'incident') {
                if (empty($rec['occurred_at'])) { $counts['incident']['skipped']++; continue; }
                // stomach_sensation/flu_symptoms_sensation/lethargy_sensation/
                // related_medication_id (Aug 2026, real bug found while
                // converting a mysqldump backup for restore) were added to
                // the medical incident category after this column list was
                // first written and never added here — a medical-category
                // incident carrying real values in any of these would have
                // silently lost them on import/restore. related_medication_id
                // is imported as-is (unlike incident_push.php's name-based
                // resolution) since a straight import/restore is expected to
                // target the SAME database the export came from, where the
                // id is still valid.
                $cols = ['category', 'occurred_at', 'ended_at', 'trigger_context', 'thoughts_before',
                         'chest_sensation', 'arm_sensation', 'shoulder_sensation', 'headache_sensation',
                         'shaking', 'stomach_sensation', 'flu_symptoms_sensation', 'lethargy_sensation',
                         'anxiety_intensity', 'duration_minutes', 'nitroglycerin_taken',
                         'what_helped_recovery', 'differed_from_pattern', 'medical_evaluation',
                         'medical_evaluation_notes', 'related_medication_id', 'free_notes'];
                $fields = [];
                foreach ($cols as $c) { $fields[$c] = array_key_exists($c, $rec) ? $rec[$c] : null; }
                if (empty($fields['category'])) $fields['category'] = 'anxiety'; // NOT NULL column
                $colList = implode(', ', array_keys($fields));
                $placeholders = implode(', ', array_map(fn($k) => ":$k", array_keys($fields)));
                $stmt = $pdo->prepare("INSERT INTO wardstock_incidents ($colList) VALUES ($placeholders)");
                $stmt->execute($fields);
                $counts['incident']['inserted']++;

            } elseif ($type === 'daily_log') {
                if (empty($rec['log_date'])) { $counts['daily_log']['skipped']++; continue; }

                $cols = ['sleep_duration_hrs', 'sleep_efficiency', 'resting_hr', 'hrv', 'weight',
                         'steps', 'exercise_minutes', 'standing_minutes', 'activity_exertion', 'caffeine',
                         'caffeine_servings', 'alcohol', 'alcohol_drinks', 'medication_notes',
                         'mood_rating', 'state_of_mind', 'free_notes'];

                $existing = $pdo->prepare('SELECT * FROM wardstock_daily_logs WHERE log_date = ?');
                $existing->execute([$rec['log_date']]);
                $existingRow = $existing->fetch();

                // Merge, don't overwrite: a partial import (e.g. weight-only history) should
                // never null out fields it simply doesn't mention on a day that already has
                // fuller data. Only fields actually present (and non-null) in the import
                // record replace what's there; everything else keeps its existing value.
                $fields = ['log_date' => $rec['log_date']];
                foreach ($cols as $c) {
                    if (array_key_exists($c, $rec) && $rec[$c] !== null) {
                        $fields[$c] = $rec[$c];
                    } elseif ($existingRow) {
                        $fields[$c] = $existingRow[$c];
                    } else {
                        $fields[$c] = null;
                    }
                }

                // Medications: only touched if the import record actually specifies them —
                // an empty/absent medications_taken in the source is not the same thing as
                // "confirmed nothing was taken," so don't let it erase real existing data.
                if (array_key_exists('medications_taken', $rec)) {
                    $medIds = [];
                    foreach (($rec['medications_taken'] ?? []) as $name) {
                        if (isset($medLookup[$name])) $medIds[] = $medLookup[$name];
                        else $unmatchedMeds[$name] = true;
                    }
                    $fields['medications_taken_json'] = json_encode(array_values($medIds));
                    $fields['medications_all_taken'] = array_key_exists('medications_all_taken', $rec)
                        ? $rec['medications_all_taken']
                        : ($existingRow['medications_all_taken'] ?? null);
                } elseif ($existingRow) {
                    $fields['medications_taken_json'] = $existingRow['medications_taken_json'];
                    $fields['medications_all_taken'] = $existingRow['medications_all_taken'];
                } else {
                    $fields['medications_taken_json'] = json_encode([]);
                    $fields['medications_all_taken'] = null;
                }

                if ($existingRow) {
                    $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($fields)));
                    $stmt = $pdo->prepare("UPDATE wardstock_daily_logs SET $set WHERE id = :id");
                    $fields['id'] = $existingRow['id'];
                    $stmt->execute($fields);
                    $counts['daily_log']['updated']++;
                } else {
                    $colList = implode(', ', array_keys($fields));
                    $placeholders = implode(', ', array_map(fn($k) => ":$k", array_keys($fields)));
                    $stmt = $pdo->prepare("INSERT INTO wardstock_daily_logs ($colList) VALUES ($placeholders)");
                    $stmt->execute($fields);
                    $counts['daily_log']['inserted']++;
                }

            } elseif ($type === 'therapy_session') {
                if (empty($rec['session_date'])) { $counts['therapy_session']['skipped']++; continue; }
                $cols = ['session_type', 'summary', 'insights', 'homework', 'mood_before', 'mood_after', 'free_notes'];

                $existing = $pdo->prepare('SELECT * FROM wardstock_therapy_sessions WHERE session_date = ? AND session_type = ?');
                $existing->execute([$rec['session_date'], $rec['session_type'] ?? 'individual']);
                $existingRow = $existing->fetch();

                $fields = ['session_date' => $rec['session_date']];
                foreach ($cols as $c) {
                    if (array_key_exists($c, $rec) && $rec[$c] !== null) {
                        $fields[$c] = $rec[$c];
                    } elseif ($existingRow) {
                        $fields[$c] = $existingRow[$c];
                    } else {
                        $fields[$c] = ($c === 'session_type') ? 'individual' : null;
                    }
                }

                if ($existingRow) {
                    $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($fields)));
                    $stmt = $pdo->prepare("UPDATE wardstock_therapy_sessions SET $set WHERE id = :id");
                    $fields['id'] = $existingRow['id'];
                    $stmt->execute($fields);
                    $counts['therapy_session']['updated']++;
                } else {
                    $colList = implode(', ', array_keys($fields));
                    $placeholders = implode(', ', array_map(fn($k) => ":$k", array_keys($fields)));
                    $stmt = $pdo->prepare("INSERT INTO wardstock_therapy_sessions ($colList) VALUES ($placeholders)");
                    $stmt->execute($fields);
                    $counts['therapy_session']['inserted']++;
                }

            } elseif ($type === 'medication') {
                // Added for the Lucius project's HA disaster-recovery
                // archive (see homeassistant/PLAN.md §4) — this record
                // type never appeared in exports before medications
                // were added to api/pull_manual_data.php. Matched by
                // (name, start_date) — a reliable natural key for one
                // specific dosage era of one medication, unlike
                // incidents which have none.
                if (empty($rec['name']) || empty($rec['start_date'])) { $counts['medication']['skipped']++; continue; }
                $cols = ['dosage', 'med_type', 'cadence', 'frequency_days', 'end_date', 'sort_order'];

                $existing = $pdo->prepare('SELECT * FROM wardstock_medications WHERE name = ? AND start_date = ?');
                $existing->execute([$rec['name'], $rec['start_date']]);
                $existingRow = $existing->fetch();

                $fields = ['name' => $rec['name'], 'start_date' => $rec['start_date']];
                foreach ($cols as $c) {
                    if (array_key_exists($c, $rec) && $rec[$c] !== null) {
                        $fields[$c] = $rec[$c];
                    } elseif ($existingRow) {
                        $fields[$c] = $existingRow[$c];
                    } else {
                        $fields[$c] = ($c === 'med_type') ? 'scheduled' : (($c === 'frequency_days') ? 1 : null);
                    }
                }

                if ($existingRow) {
                    $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($fields)));
                    $stmt = $pdo->prepare("UPDATE wardstock_medications SET $set WHERE id = :id");
                    $fields['id'] = $existingRow['id'];
                    $stmt->execute($fields);
                    $counts['medication']['updated']++;
                } else {
                    $colList = implode(', ', array_keys($fields));
                    $placeholders = implode(', ', array_map(fn($k) => ":$k", array_keys($fields)));
                    $stmt = $pdo->prepare("INSERT INTO wardstock_medications ($colList) VALUES ($placeholders)");
                    $stmt->execute($fields);
                    $counts['medication']['inserted']++;
                }

            } elseif ($type === 'medication_dosage_history') {
                // Fulgrim/wherewhen (PLAN.md §11 #8). medication_id
                // isn't portable across a reimport — medications
                // above are matched/inserted by (name, start_date),
                // which can assign a different id on this database
                // than the source had. Resolve the target row via
                // medication_name + changed_at instead, since
                // medication_form.php always sets changed_at equal
                // to the new dosage era's own start_date. Insert-only
                // table (no update path exists), so a matching
                // existing row just means "already imported."
                if (empty($rec['medication_name']) || empty($rec['changed_at'])) {
                    $counts['medication_dosage_history']['skipped']++; continue;
                }
                $medRow = $pdo->prepare('SELECT id FROM wardstock_medications WHERE name = ? AND start_date = ?');
                $medRow->execute([$rec['medication_name'], substr($rec['changed_at'], 0, 10)]);
                $medId = $medRow->fetchColumn();
                if (!$medId) { $counts['medication_dosage_history']['skipped']++; continue; }

                $existing = $pdo->prepare('SELECT id FROM wardstock_medication_dosage_history WHERE medication_id = ? AND changed_at = ?');
                $existing->execute([$medId, $rec['changed_at']]);
                if ($existing->fetch()) { $counts['medication_dosage_history']['skipped']++; continue; }

                $stmt = $pdo->prepare('INSERT INTO wardstock_medication_dosage_history (medication_id, old_dosage, new_dosage, changed_at, notes) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([$medId, $rec['old_dosage'] ?? null, $rec['new_dosage'] ?? '', $rec['changed_at'], $rec['notes'] ?? null]);
                $counts['medication_dosage_history']['inserted']++;

            } elseif ($type === 'ecg_recording') {
                // Aug 2026 (EKG_DESIGN.md) — matched by (recorded_at,
                // device_product), the same reasonable-natural-key
                // idea as therapy_session's (session_date,
                // session_type). related_incident_id is deliberately
                // NOT imported — an incident id isn't portable across
                // databases (same reason incidents' own
                // related_medication_id is excluded from ITS import
                // above), and there's no source-side natural key to
                // re-resolve it through. The PDF artifact itself
                // never travels through this path either — exports
                // only ever carry the recording summary, per
                // build_export_records()'s own comment.
                if (empty($rec['recorded_at'])) { $counts['ecg_recording']['skipped']++; continue; }
                $cols = ['device_product', 'lead_configuration', 'duration_seconds', 'average_heart_rate_bpm',
                         'determination_code', 'determination_text', 'signal_quality', 'recording_reason',
                         'symptoms_present', 'symptoms_json', 'activity_before', 'rest_minutes_before',
                         'notes', 'clinician_reviewed', 'clinician_interpretation', 'clinician_reviewer_name',
                         'clinician_reviewed_at'];

                $existing = $pdo->prepare('SELECT * FROM wardstock_ecg_recordings WHERE recorded_at = ? AND device_product = ?');
                $existing->execute([$rec['recorded_at'], $rec['device_product'] ?? 'KardiaMobile']);
                $existingRow = $existing->fetch();

                $fields = ['recorded_at' => $rec['recorded_at'], 'device_product' => $rec['device_product'] ?? 'KardiaMobile'];
                foreach ($cols as $c) {
                    if ($c === 'device_product') continue; // already set above
                    if (array_key_exists($c, $rec) && $rec[$c] !== null) {
                        $fields[$c] = $rec[$c];
                    } elseif ($existingRow) {
                        $fields[$c] = $existingRow[$c];
                    } else {
                        $fields[$c] = ($c === 'lead_configuration') ? 'single_lead_i'
                            : ($c === 'signal_quality' ? 'unknown'
                            : ($c === 'recording_reason' ? 'periodic_baseline'
                            : ($c === 'symptoms_present' || $c === 'clinician_reviewed' ? 0 : null)));
                    }
                }

                if ($existingRow) {
                    $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($fields)));
                    $stmt = $pdo->prepare("UPDATE wardstock_ecg_recordings SET $set WHERE id = :id");
                    $fields['id'] = $existingRow['id'];
                    $stmt->execute($fields);
                    $counts['ecg_recording']['updated']++;
                } else {
                    $colList = implode(', ', array_keys($fields));
                    $placeholders = implode(', ', array_map(fn($k) => ":$k", array_keys($fields)));
                    $stmt = $pdo->prepare("INSERT INTO wardstock_ecg_recordings ($colList) VALUES ($placeholders)");
                    $stmt->execute($fields);
                    $counts['ecg_recording']['inserted']++;
                }
            }
        }

        $pdo->commit();
        return ['counts' => $counts, 'unmatchedMeds' => array_keys($unmatchedMeds)];
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}
