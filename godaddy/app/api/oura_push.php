<?php
// Lucius project — Home Assistant piece POSTs Oura summary fields here
// after its own 4-hour Oura pull (see homeassistant/PLAN.md §1, §5).
// Reuses the exact same merge-safe upsert oura_sync.php's manual pull
// uses (oura_upsert_daily_log() in oura.php) — one implementation, not two.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../oura.php';

header('Content-Type: application/json');
$pdo = get_db();

if (!check_api_token()) {
    http_response_code(401);
    log_ha_sync($pdo, 'oura_push', 'auth_invalid', 'missing/invalid bearer token');
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body) || !isset($body['date'])) {
    http_response_code(400);
    log_ha_sync($pdo, 'oura_push', 'malformed_request', 'missing date, or body was not valid JSON');
    echo json_encode(['error' => 'malformed_request', 'detail' => 'expected JSON body with a "date" field']);
    exit;
}

$date = $body['date'];
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    log_ha_sync($pdo, 'oura_push', 'validation_error', "invalid date format: $date");
    echo json_encode(['error' => 'validation_error', 'detail' => 'date must be YYYY-MM-DD']);
    exit;
}

// Only accept the known summary fields — never let a caller write arbitrary
// daily_logs columns, even an authenticated one. See oura_push_allowed_fields()
// in oura.php for the whitelist.
$allowed = oura_push_allowed_fields();
$fields = [];
foreach ($allowed as $col) {
    if (array_key_exists($col, $body) && $body[$col] !== null) {
        $fields[$col] = $body[$col];
    }
}

if (!$fields) {
    http_response_code(400);
    log_ha_sync($pdo, 'oura_push', 'validation_error', 'no recognized fields in body (expected one or more of: ' . implode(', ', $allowed) . ')');
    echo json_encode(['error' => 'validation_error', 'detail' => 'no recognized fields provided']);
    exit;
}

try {
    $logId = oura_upsert_daily_log($pdo, $date, $fields);
    log_ha_sync($pdo, 'oura_push', 'success', "date=$date fields=" . implode(',', array_keys($fields)));
    echo json_encode(['success' => true, 'date' => $date, 'daily_log_id' => (int)$logId, 'fields_written' => array_keys($fields)]);
} catch (Exception $e) {
    http_response_code(500);
    log_ha_sync($pdo, 'oura_push', 'db_error', $e->getMessage());
    echo json_encode(['error' => 'db_error']);
}
