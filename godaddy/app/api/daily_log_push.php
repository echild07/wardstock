<?php
// beewell/standwhy write path (Aug 2026) — the registered publish_endpoint
// for every wardstock_* destination in beewell/ROUTING.md that lands in
// the Daily Log (wardstock_note, wardstock_med, wardstock_mind,
// wardstock_weight_dictated, and any future one). Ward's own framing:
// "the manual entry daily entry screen is the interface" — one endpoint,
// covering the same field set as daily_form.php, not one endpoint per tag.
// Standwhy's outbound worker (standwhy/REGISTRY.md §3a) POSTs here once a
// message is approved; this file never talks to standwhy directly, same
// "producer never calls a consumer's endpoint, but a consumer's own
// endpoint doesn't need to know who called it" boundary the registry
// design already draws.
//
// Merge logic (which fields get overwritten vs. appended vs. left alone)
// lives in db.php's merge_daily_log_fields() — kept there, not here, so
// any other future caller with the same "write into today's Daily Log"
// need reuses the exact same safety rules instead of a second copy.
//
// POST, bearer token (API_SYNC_TOKEN), JSON body:
// {
//   "log_date": "2026-08-25",
//   "source": "beewell",             -- free text, stamped into any appended note's tag
//   "external_ref": "bee:utt:...",   -- required for idempotency on appended text fields
//   "fields": {
//     "weight": 255,                 -- any subset of daily_form.php's own fields,
//     "free_notes": "Slept badly...",   under their real wardstock_daily_logs column names
//     ...
//   }
// }
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
header('Content-Type: application/json');

$pdo = get_db();

if (!check_api_token()) {
    http_response_code(401);
    log_ha_sync($pdo, 'daily_log_push', 'auth_invalid', 'missing/invalid bearer token');
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body) || empty($body['log_date']) || empty($body['fields']) || !is_array($body['fields'])) {
    http_response_code(400);
    log_ha_sync($pdo, 'daily_log_push', 'malformed_request', 'missing log_date/fields, or body was not valid JSON');
    echo json_encode(['error' => 'malformed_request', 'detail' => 'expected JSON body with "log_date" and a "fields" object']);
    exit;
}

$date = $body['log_date'];
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    log_ha_sync($pdo, 'daily_log_push', 'validation_error', "invalid log_date: $date");
    echo json_encode(['error' => 'validation_error', 'detail' => 'log_date must be YYYY-MM-DD']);
    exit;
}

$source = trim((string)($body['source'] ?? 'unknown'));
$externalRef = trim((string)($body['external_ref'] ?? ''));

try {
    $report = merge_daily_log_fields($pdo, $date, $body['fields'], $source, $externalRef);
    $wroteAnything = false;
    foreach (array_merge($report['numeric'], $report['text']) as $r) {
        if (!empty($r['written'])) { $wroteAnything = true; break; }
    }
    log_ha_sync($pdo, 'daily_log_push', 'success', "date=$date source=$source ref=$externalRef wrote=" . ($wroteAnything ? 'yes' : 'no'));
    echo json_encode(['success' => true, 'log_date' => $date] + $report);
} catch (Throwable $e) {
    http_response_code(500);
    log_ha_sync($pdo, 'daily_log_push', 'db_error', $e->getMessage());
    echo json_encode(['error' => 'db_error']);
}
