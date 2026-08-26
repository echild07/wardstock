<?php
// Lucius project — Home Assistant piece pulls full incidents/daily_logs/
// therapy_sessions here every 15 minutes (see homeassistant/PLAN.md §1,
// §4, §5). Returns FULL records (not a trimmed subset) — HA is meant to
// be able to restore from this via WardStock's existing import.php if
// GoDaddy's database is ever lost (§4's disaster-recovery decision).
// Reuses build_export_records() (db.php) — same shape the human-facing
// Export page produces, so there is one implementation of "what a record
// export looks like," not two that could quietly drift apart.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';

header('Content-Type: application/json');
$pdo = get_db();

if (!check_api_token()) {
    http_response_code(401);
    log_ha_sync($pdo, 'pull_manual_data', 'auth_invalid', 'missing/invalid bearer token');
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

try {
    // Own bookkeeping key, deliberately separate from the human Export
    // page's last_export_at — different schedule, different purpose,
    // must not interfere with each other's "since" calculations.
    $scope = $_GET['scope'] ?? 'since_last';
    $lastPull = get_setting($pdo, 'last_ha_pull_at');
    $since = ($scope === 'since_last' && $lastPull) ? $lastPull : null;

    // Full records: incidents, daily_logs, therapy_sessions, medications,
    // AND medication_dosage_history (Fulgrim/wherewhen, PLAN.md §11 #8) —
    // same reasoning as medications itself: export.php's human-facing
    // export never requests these, but HA's disaster-recovery copy needs
    // them to actually be complete. ecg_recordings added Aug 2026 (see
    // homeassistant/EKG_DESIGN.md) — metadata/summary fields only, never
    // the PDF blob itself (build_export_records() explains why).
    $types = ['incidents', 'daily_logs', 'therapy_sessions', 'medications', 'medication_dosage_history', 'ecg_recordings'];
    $data = build_export_records($pdo, $since, $types);
    $data = array_merge(['pulled_at' => date('c'), 'scope' => $scope, 'since' => $since], $data);

    set_setting($pdo, 'last_ha_pull_at', date('Y-m-d H:i:s'));

    $counts = [];
    foreach ($data['records'] as $r) {
        $counts[$r['record_type']] = ($counts[$r['record_type']] ?? 0) + 1;
    }
    log_ha_sync($pdo, 'pull_manual_data', 'success', 'scope=' . $scope . ' counts=' . json_encode($counts));

    echo json_encode($data, JSON_PRETTY_PRINT);
} catch (Exception $e) {
    http_response_code(500);
    log_ha_sync($pdo, 'pull_manual_data', 'db_error', $e->getMessage());
    echo json_encode(['error' => 'db_error']);
}
