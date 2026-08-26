<?php
// Lucius project — Home Assistant piece POSTs a single day's weight here
// after its Body Composition Import flow (see homeassistant/PLAN.md §14).
// Only the weight number ever reaches GoDaddy — the other 13 metrics the
// scale exports live in InfluxDB only, per the "almost nothing touches
// GoDaddy" design decision in §14 (Ward's call: rich metrics are for his
// own analysis, not part of what the app is for).
//
// Never overwrites an existing value (push_weight_if_unset() in db.php) —
// respects a manual entry made before or after the scale data arrives,
// and makes re-importing the same historical rows a safe no-op by default.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';

header('Content-Type: application/json');
$pdo = get_db();

if (!check_api_token()) {
    http_response_code(401);
    log_ha_sync($pdo, 'weight_push', 'auth_invalid', 'missing/invalid bearer token');
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body) || !isset($body['date']) || !isset($body['weight_lb'])) {
    http_response_code(400);
    log_ha_sync($pdo, 'weight_push', 'malformed_request', 'missing date/weight_lb, or body was not valid JSON');
    echo json_encode(['error' => 'malformed_request', 'detail' => 'expected JSON body with "date" and "weight_lb" fields']);
    exit;
}

$date = $body['date'];
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    log_ha_sync($pdo, 'weight_push', 'validation_error', "invalid date format: $date");
    echo json_encode(['error' => 'validation_error', 'detail' => 'date must be YYYY-MM-DD']);
    exit;
}

$weight = $body['weight_lb'];
if (!is_numeric($weight) || $weight <= 0 || $weight > 999) {
    http_response_code(400);
    log_ha_sync($pdo, 'weight_push', 'validation_error', "invalid weight_lb: " . json_encode($weight));
    echo json_encode(['error' => 'validation_error', 'detail' => 'weight_lb must be a plausible positive number']);
    exit;
}

try {
    $result = push_weight_if_unset($pdo, $date, (float)$weight);
    $wasSet = !empty($result['written']);
    if ($wasSet) {
        log_ha_sync($pdo, 'weight_push', 'success', "date=$date weight_lb=$weight (written)");
        echo json_encode(['success' => true, 'date' => $date, 'weight_written' => true, 'skipped' => false]);
    } else {
        $have = $result['existing_lb'];
        log_ha_sync($pdo, 'weight_push', 'success', "date=$date skipped — Daily Log already has $have lb (scale sent $weight, not overwritten)");
        echo json_encode([
            'success' => true,
            'date' => $date,
            'weight_written' => false,
            'skipped' => true,
            'reason' => 'already_set',
            'existing_weight_lb' => $have,
            'offered_weight_lb' => (float)$weight,
        ]);
    }
} catch (Exception $e) {
    http_response_code(500);
    log_ha_sync($pdo, 'weight_push', 'db_error', $e->getMessage());
    echo json_encode(['error' => 'db_error']);
}
