<?php
// Plant / beewell → GoDaddy: merge-safe EKG summary (EKG_DESIGN.md).
// Metadata only — PDF bytes stay on /share/beewell/completed, never in this POST.
// Matched by (recorded_at, device_product), same as import_records('ecg_recording').
//
// POST, bearer token (API_SYNC_TOKEN), JSON:
// {
//   "external_ref": "email:...",
//   "recorded_at": "2026-08-20 10:42:18",
//   "device_product": "KardiaMobile",
//   "lead_configuration": "single_lead_i",
//   "duration_seconds": 30,
//   "average_heart_rate_bpm": 72,
//   "determination_code": "normal_sinus_rhythm",
//   "determination_text": "Normal Sinus Rhythm",
//   "signal_quality": "unknown",
//   "recording_reason": "periodic_baseline",
//   "notes": "optional"
// }

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
header('Content-Type: application/json');

$pdo = get_db();

if (!check_api_token()) {
    http_response_code(401);
    log_ha_sync($pdo, 'ekg_push', 'auth_invalid', 'missing/invalid bearer token');
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body) || empty($body['recorded_at'])) {
    http_response_code(400);
    log_ha_sync($pdo, 'ekg_push', 'malformed_request', 'missing recorded_at');
    echo json_encode(['error' => 'malformed_request', 'detail' => 'recorded_at is required']);
    exit;
}

$recordedAt = trim((string)$body['recorded_at']);
$recordedAt = str_replace('T', ' ', $recordedAt);
$recordedAt = preg_replace('/Z$/', '', $recordedAt);
$recordedAt = preg_replace('/[+-]\d{2}:\d{2}$/', '', $recordedAt);
if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $recordedAt)) {
    http_response_code(400);
    log_ha_sync($pdo, 'ekg_push', 'validation_error', 'recorded_at shape');
    echo json_encode(['error' => 'validation_error', 'detail' => 'recorded_at must start with YYYY-MM-DD']);
    exit;
}

$rec = $body;
$rec['recorded_at'] = $recordedAt;
$rec['record_type'] = 'ecg_recording';

try {
    $summary = import_records($pdo, [$rec]);
    $c = $summary['counts']['ecg_recording'];
    log_ha_sync($pdo, 'ekg_push', 'success', 'inserted=' . $c['inserted'] . ' updated=' . $c['updated']);
    echo json_encode(['success' => true, 'ecg_recording' => $c, 'external_ref' => $body['external_ref'] ?? null]);
} catch (Throwable $e) {
    http_response_code(500);
    log_ha_sync($pdo, 'ekg_push', 'db_error', $e->getMessage());
    echo json_encode(['error' => 'db_error']);
}
