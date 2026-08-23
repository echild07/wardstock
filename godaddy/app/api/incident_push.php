<?php
// Fulgrim/wherewhen — Home Assistant's Medical History Import flow
// (homeassistant/PLAN.md §19) POSTs one incident here per encounter in
// the structured medical-history YAML. Token-authenticated, same pattern
// as every other api/*.php endpoint.
//
// Upsert by `external_ref` (required) — the YAML entry's stable `id`
// slug, e.g. "cabg-2013". Incidents otherwise have no reliable natural
// key (see import.php's own comment on this), so without this, rerunning
// the import flow on the same file would create duplicates every time.
// A human-entered incident (incident_form.php) never sets external_ref —
// this field is machine-import-only, not shown anywhere in that form.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';

header('Content-Type: application/json');
$pdo = get_db();

if (!check_api_token()) {
    http_response_code(401);
    log_ha_sync($pdo, 'incident_push', 'auth_invalid', 'missing/invalid bearer token');
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body) || empty($body['external_ref']) || empty($body['occurred_at']) || empty($body['category'])) {
    http_response_code(400);
    log_ha_sync($pdo, 'incident_push', 'malformed_request', 'missing external_ref/occurred_at/category, or body was not valid JSON');
    echo json_encode(['error' => 'malformed_request', 'detail' => 'expected JSON body with "external_ref", "occurred_at", and "category" fields']);
    exit;
}

$category = in_array($body['category'], ['cardiac', 'medical'], true) ? $body['category'] : null;
if (!$category) {
    http_response_code(400);
    log_ha_sync($pdo, 'incident_push', 'validation_error', "invalid category: " . json_encode($body['category']));
    echo json_encode(['error' => 'validation_error', 'detail' => 'category must be "cardiac" or "medical" — this endpoint is for the medical-history import, not live anxiety logging']);
    exit;
}

// related_medication_name is resolved server-side, same reasoning as
// import.php's medication_dosage_history handling — the import flow
// shouldn't need to know GoDaddy's internal medication ids. Left null
// (not an error) if it doesn't match anything current, since historical
// medication names may predate what's in the Medications list today.
$relatedMedId = null;
if (!empty($body['related_medication_name'])) {
    $stmt = $pdo->prepare('SELECT id FROM wardstock_medications WHERE name = ? ORDER BY start_date DESC LIMIT 1');
    $stmt->execute([$body['related_medication_name']]);
    $relatedMedId = $stmt->fetchColumn() ?: null;
}

$fields = [
    'category' => $category,
    'occurred_at' => $body['occurred_at'],
    'ended_at' => $body['ended_at'] ?? null,
    'medical_evaluation' => 'yes',
    'medical_evaluation_notes' => $body['medical_evaluation_notes'] ?? null,
    'stomach_sensation' => $body['stomach_sensation'] ?? 'none',
    'flu_symptoms_sensation' => $body['flu_symptoms_sensation'] ?? 'none',
    'lethargy_sensation' => $body['lethargy_sensation'] ?? 'none',
    'related_medication_id' => $relatedMedId,
    'free_notes' => $body['free_notes'] ?? null,
    'external_ref' => $body['external_ref'],
];

try {
    $existing = $pdo->prepare('SELECT id FROM wardstock_incidents WHERE external_ref = ?');
    $existing->execute([$fields['external_ref']]);
    $existingId = $existing->fetchColumn();

    if ($existingId) {
        $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($fields)));
        $stmt = $pdo->prepare("UPDATE wardstock_incidents SET $set WHERE id = :id");
        $fields['id'] = $existingId;
        $stmt->execute($fields);
        $action = 'updated';
        $id = $existingId;
    } else {
        $cols = implode(', ', array_keys($fields));
        $placeholders = implode(', ', array_map(fn($k) => ":$k", array_keys($fields)));
        $stmt = $pdo->prepare("INSERT INTO wardstock_incidents ($cols) VALUES ($placeholders)");
        $stmt->execute($fields);
        $action = 'inserted';
        $id = $pdo->lastInsertId();
    }

    log_ha_sync($pdo, 'incident_push', 'success', "external_ref={$fields['external_ref']} action=$action incident_id=$id");
    echo json_encode(['success' => true, 'action' => $action, 'incident_id' => (int)$id]);
} catch (Exception $e) {
    http_response_code(500);
    log_ha_sync($pdo, 'incident_push', 'db_error', $e->getMessage());
    echo json_encode(['error' => 'db_error']);
}
