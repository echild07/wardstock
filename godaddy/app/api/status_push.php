<?php
// Lucius project — Home Assistant piece POSTs a consolidated status
// snapshot here from its "Status Heartbeat" flow (see homeassistant/PLAN.md
// §15), every 15 minutes. Feeds status.php's three-category view (HA /
// Node-RED / Analytics) — deliberately a separate, dedicated reporting
// flow rather than each sync flow pushing its own status inline, so this
// endpoint always gets one full snapshot per call, not partial updates
// from three different flows racing each other.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';

header('Content-Type: application/json');
$pdo = get_db();

if (!check_api_token()) {
    http_response_code(401);
    log_ha_sync($pdo, 'status_push', 'auth_invalid', 'missing/invalid bearer token');
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body) || !isset($body['reports']) || !is_array($body['reports'])) {
    http_response_code(400);
    log_ha_sync($pdo, 'status_push', 'malformed_request', 'missing "reports" array, or body was not valid JSON');
    echo json_encode(['error' => 'malformed_request', 'detail' => 'expected JSON body with a "reports" array']);
    exit;
}

$validCategories = ['ha', 'nodered', 'analytics'];
$upserted = 0;
$errors = [];

try {
    $stmt = $pdo->prepare(
        'INSERT INTO system_status_reports
            (category, component, last_run_at, last_status, last_error, detail, expected_frequency_minutes)
         VALUES (:category, :component, :last_run_at, :last_status, :last_error, :detail, :expected_frequency_minutes)
         ON DUPLICATE KEY UPDATE
            last_run_at = VALUES(last_run_at),
            last_status = VALUES(last_status),
            last_error = VALUES(last_error),
            detail = VALUES(detail),
            expected_frequency_minutes = VALUES(expected_frequency_minutes)'
    );

    foreach ($body['reports'] as $r) {
        if (!is_array($r) || empty($r['category']) || empty($r['component']) || !in_array($r['category'], $validCategories, true)) {
            $errors[] = 'skipped a report with missing/invalid category or component: ' . json_encode($r);
            continue;
        }
        $stmt->execute([
            'category' => $r['category'],
            'component' => $r['component'],
            'last_run_at' => $r['last_run_at'] ?? null,
            'last_status' => $r['last_status'] ?? null,
            'last_error' => $r['last_error'] ?? null,
            'detail' => isset($r['detail']) ? (is_string($r['detail']) ? $r['detail'] : json_encode($r['detail'])) : null,
            'expected_frequency_minutes' => $r['expected_frequency_minutes'] ?? null,
        ]);
        $upserted++;
    }

    if ($upserted === 0) {
        http_response_code(400);
        log_ha_sync($pdo, 'status_push', 'validation_error', 'no valid reports in payload: ' . implode('; ', $errors));
        echo json_encode(['error' => 'validation_error', 'detail' => 'no valid reports in payload', 'errors' => $errors]);
        exit;
    }

    log_ha_sync($pdo, 'status_push', 'success', "upserted=$upserted" . ($errors ? ' skipped=' . count($errors) : ''));
    echo json_encode(['success' => true, 'upserted' => $upserted, 'skipped' => $errors]);
} catch (Exception $e) {
    http_response_code(500);
    log_ha_sync($pdo, 'status_push', 'db_error', $e->getMessage());
    echo json_encode(['error' => 'db_error']);
}
