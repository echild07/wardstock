<?php
// beewell/standwhy: one POST for a scale export that may contain many days.
// Only weight_lb reaches daily_logs (write-once-if-unset via push_weight_if_unset).
// Rich metrics stay in Influx via the existing body_comp_import_flow if you also
// drop the .xlsx there; this endpoint does not write Influx.
//
// POST, bearer token:
// { "external_ref": "email:...", "readings": [ { "date": "2026-08-20", "weight_lb": 180.2 }, ... ] }

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
header('Content-Type: application/json');

$pdo = get_db();

if (!check_api_token()) {
    http_response_code(401);
    log_ha_sync($pdo, 'body_comp_push', 'auth_invalid', 'missing/invalid bearer token');
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
$readings = is_array($body) ? ($body['readings'] ?? null) : null;
if (!is_array($readings) || !$readings) {
    http_response_code(400);
    log_ha_sync($pdo, 'body_comp_push', 'malformed_request', 'readings[] required');
    echo json_encode(['error' => 'malformed_request', 'detail' => 'expected readings array with date and weight_lb']);
    exit;
}

$written = 0;
$skipped = 0;
$errors = [];
try {
    foreach ($readings as $i => $row) {
        if (!is_array($row)) {
            continue;
        }
        $date = trim((string)($row['date'] ?? ''));
        $weight = $row['weight_lb'] ?? null;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !is_numeric($weight) || $weight <= 0 || $weight > 999) {
            $errors[] = "row $i invalid date/weight";
            continue;
        }
        $result = push_weight_if_unset($pdo, $date, (float)$weight);
        if (!empty($result['written'])) {
            $written++;
        } else {
            $skipped++;
        }
    }
    log_ha_sync($pdo, 'body_comp_push', 'success', 'written=' . $written . ' skipped=' . $skipped);
    echo json_encode([
        'success' => true,
        'external_ref' => $body['external_ref'] ?? null,
        'weight_written' => $written,
        'skipped' => $skipped,
        'errors' => $errors,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    log_ha_sync($pdo, 'body_comp_push', 'db_error', $e->getMessage());
    echo json_encode(['error' => 'db_error']);
}
