<?php
// Home Assistant's own "restore from HA's local backup" flow (Aug 2026,
// see homeassistant/PLAN.md — the GoDaddy-Restore Node-RED flow) POSTs
// here after a database wipe/rebuild on this side, with the exact same
// {"records": [...]} shape api/pull_manual_data.php already hands it
// every day for its local SQLite backup. Token-authenticated, same
// pattern as every other api/*.php endpoint — never the human login.
//
// Deliberately reuses import_records() (db.php) rather than a second
// implementation — the exact same merge-safe upsert logic the human-
// facing import.php page has always used, so a machine-triggered
// restore and a manual one behave identically (same natural-key
// matching, same "merge don't overwrite" rule for daily_logs, same
// transaction-or-nothing guarantee).
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';

header('Content-Type: application/json');
$pdo = get_db();

if (!check_api_token()) {
    http_response_code(401);
    log_ha_sync($pdo, 'bulk_restore', 'auth_invalid', 'missing/invalid bearer token');
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body) || !isset($body['records']) || !is_array($body['records'])) {
    http_response_code(400);
    log_ha_sync($pdo, 'bulk_restore', 'malformed_request', 'missing "records" array, or body was not valid JSON');
    echo json_encode(['error' => 'malformed_request', 'detail' => 'expected JSON body with a "records" array, same shape pull_manual_data.php returns']);
    exit;
}

try {
    $summary = import_records($pdo, $body['records']);
    $counts = $summary['counts'];
    log_ha_sync($pdo, 'bulk_restore', 'success', 'records_in=' . count($body['records']) . ' counts=' . json_encode($counts));
    echo json_encode(['success' => true, 'counts' => $counts, 'unmatchedMeds' => $summary['unmatchedMeds']]);
} catch (Exception $e) {
    http_response_code(500);
    log_ha_sync($pdo, 'bulk_restore', 'db_error', $e->getMessage());
    echo json_encode(['error' => 'db_error', 'detail' => $e->getMessage()]);
}
