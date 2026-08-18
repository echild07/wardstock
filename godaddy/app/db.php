<?php
require_once __DIR__ . '/config/config.php';

function get_db() {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
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
    $stmt = $pdo->prepare('INSERT INTO ha_sync_log (endpoint, status_code, detail) VALUES (?, ?, ?)');
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
    $existing = $pdo->prepare('SELECT id, weight FROM daily_logs WHERE log_date = ?');
    $existing->execute([$date]);
    $row = $existing->fetch();

    if ($row) {
        if ($row['weight'] !== null) {
            return false;
        }
        $stmt = $pdo->prepare('UPDATE daily_logs SET weight = ? WHERE id = ?');
        $stmt->execute([$weightLb, $row['id']]);
        return true;
    }

    $stmt = $pdo->prepare('INSERT INTO daily_logs (log_date, weight) VALUES (?, ?)');
    $stmt->execute([$date, $weightLb]);
    return true;
}

// Was duplicated separately in export.php and debug.php — consolidated
// here since more files (the new api/*.php endpoints) now need it too.
function get_setting($pdo, $key) {
    $stmt = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row ? $row['setting_value'] : null;
}
function set_setting($pdo, $key, $value) {
    $stmt = $pdo->prepare('INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)
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
    foreach ($pdo->query('SELECT id, name FROM medications')->fetchAll() as $m) {
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
        foreach ($pdo->query('SELECT * FROM medications ORDER BY sort_order, name')->fetchAll() as $row) {
            $row['record_type'] = 'medication';
            $data['records'][] = $row;
        }
    }

    if (in_array('incidents', $types)) {
        $sql = 'SELECT * FROM incidents' . ($since ? ' WHERE updated_at > :since' : '') . ' ORDER BY occurred_at';
        $stmt = $pdo->prepare($sql);
        if ($since) $stmt->execute(['since' => $since]); else $stmt->execute();
        foreach ($stmt->fetchAll() as $row) { $row['record_type'] = 'incident'; $data['records'][] = $row; }
    }
    if (in_array('daily_logs', $types)) {
        $sql = 'SELECT * FROM daily_logs' . ($since ? ' WHERE updated_at > :since' : '') . ' ORDER BY log_date';
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
        $sql = 'SELECT * FROM therapy_sessions' . ($since ? ' WHERE updated_at > :since' : '') . ' ORDER BY session_date';
        $stmt = $pdo->prepare($sql);
        if ($since) $stmt->execute(['since' => $since]); else $stmt->execute();
        foreach ($stmt->fetchAll() as $row) { $row['record_type'] = 'therapy_session'; $data['records'][] = $row; }
    }

    return $data;
}
