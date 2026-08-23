<?php
// Fulgrim/wherewhen — Home Assistant's Flux analysis engine (PLAN.md §11
// "Where results go") POSTs one analysis result here per computed
// period. Token-authenticated, same pattern as every other api/*.php
// endpoint. Independently curl-testable — this endpoint doesn't need the
// actual Flux queries to exist to be verified (PLAN.md build-order
// convention, CLAUDE.md "Conventions worth knowing").
//
// Upsert by (analysis_key, period_type) — matches analysis_results' own
// UNIQUE KEY, so every run of a given tier updates that tier's one row
// in place rather than accumulating a new one (Ward, Aug 2026 — real
// design gap: the key used to also include period_start/period_end,
// which shift on every run of a rolling-window tier, so every run
// inserted instead of updating, and analysis_results grew without
// bound). period_start/period_end/analysis_version still get written
// (informational — which window this row actually covers, and a manual
// schema/logic-version marker) but no longer determine insert-vs-update.
// See the long comment on this table in schema.sql for the full story.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';

header('Content-Type: application/json');
$pdo = get_db();

if (!check_api_token()) {
    http_response_code(401);
    log_ha_sync($pdo, 'analysis_push', 'auth_invalid', 'missing/invalid bearer token');
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
$required = ['analysis_key', 'period_type', 'result'];
if (!is_array($body) || array_diff($required, array_keys($body))) {
    http_response_code(400);
    log_ha_sync($pdo, 'analysis_push', 'malformed_request', 'missing analysis_key/period_type/result, or body was not valid JSON');
    echo json_encode(['error' => 'malformed_request', 'detail' => 'expected JSON body with "analysis_key", "period_type", and "result" fields']);
    exit;
}

$allowedPeriods = ['daily', 'weekly', 'monthly', 'all'];
if (!in_array($body['period_type'], $allowedPeriods, true)) {
    http_response_code(400);
    log_ha_sync($pdo, 'analysis_push', 'validation_error', "invalid period_type: " . json_encode($body['period_type']));
    echo json_encode(['error' => 'validation_error', 'detail' => 'period_type must be one of: ' . implode(', ', $allowedPeriods)]);
    exit;
}

$fields = [
    'analysis_key' => $body['analysis_key'],
    'period_type' => $body['period_type'],
    'period_start' => $body['period_start'] ?? null,
    'period_end' => $body['period_end'] ?? null,
    'analysis_version' => (int)($body['analysis_version'] ?? 1),
    'result_json' => is_string($body['result']) ? $body['result'] : json_encode($body['result']),
    'computed_at' => $body['computed_at'] ?? date('Y-m-d H:i:s'),
];

try {
    // Matches the UNIQUE KEY analysis_period_version — ON DUPLICATE KEY
    // UPDATE is the natural upsert here, same idea as app_settings' own
    // db_version stamp elsewhere in this project's SQL.
    $cols = implode(', ', array_keys($fields));
    $placeholders = implode(', ', array_map(fn($k) => ":$k", array_keys($fields)));
    $updates = implode(', ', array_map(fn($k) => "$k = VALUES($k)", array_keys($fields)));
    $stmt = $pdo->prepare("INSERT INTO wardstock_analysis_results ($cols) VALUES ($placeholders)
                            ON DUPLICATE KEY UPDATE $updates");
    $stmt->execute($fields);

    log_ha_sync($pdo, 'analysis_push', 'success', "analysis_key={$fields['analysis_key']} period_type={$fields['period_type']} period={$fields['period_start']}..{$fields['period_end']} version={$fields['analysis_version']}");
    echo json_encode(['success' => true, 'analysis_key' => $fields['analysis_key'], 'period_type' => $fields['period_type']]);
} catch (Exception $e) {
    http_response_code(500);
    log_ha_sync($pdo, 'analysis_push', 'db_error', $e->getMessage());
    echo json_encode(['error' => 'db_error']);
}
