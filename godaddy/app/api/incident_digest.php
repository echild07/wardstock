<?php
// HA evening digest: incidents whose occurred_at falls on one local calendar
// day (preferred_timezone). Read-only GET. Empty list is a successful no-mail
// signal — the Node-RED/Python sender must not email on count=0.

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';

header('Content-Type: application/json');
$pdo = get_db();

if (!check_api_token()) {
    http_response_code(401);
    log_ha_sync($pdo, 'incident_digest', 'auth_invalid', 'missing/invalid bearer token');
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

$tzName = get_setting($pdo, 'preferred_timezone') ?: 'America/New_York';
$day = $_GET['day'] ?? '';
if ($day === '' || strtolower($day) === 'today') {
    $day = app_today($pdo);
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
    http_response_code(400);
    log_ha_sync($pdo, 'incident_digest', 'malformed_request', 'day must be YYYY-MM-DD or today');
    echo json_encode(['error' => 'malformed_request', 'detail' => 'day must be YYYY-MM-DD or omitted/today']);
    exit;
}

function digest_clip($s, $n = 280) {
    $s = trim((string) $s);
    if ($s === '') {
        return null;
    }
    if (mb_strlen($s) <= $n) {
        return $s;
    }
    return mb_substr($s, 0, $n - 1) . '…';
}

try {
    // occurred_at is a naive DATETIME from the form (browser local / preferred
    // zone), not UTC — DATE() is the calendar day Ward meant.
    $stmt = $pdo->prepare(
        'SELECT id, category, occurred_at, ended_at, anxiety_intensity,
                duration_minutes, chest_sensation, trigger_context, free_notes,
                medical_evaluation, nitroglycerin_taken
         FROM wardstock_incidents
         WHERE DATE(occurred_at) = ?
         ORDER BY occurred_at ASC, id ASC'
    );
    $stmt->execute([$day]);
    $rows = $stmt->fetchAll();
    $incidents = [];
    foreach ($rows as $row) {
        $incidents[] = [
            'id' => (int) $row['id'],
            'category' => $row['category'],
            'occurred_at' => $row['occurred_at'],
            'ended_at' => $row['ended_at'],
            'anxiety_intensity' => $row['anxiety_intensity'] !== null ? (int) $row['anxiety_intensity'] : null,
            'duration_minutes' => $row['duration_minutes'] !== null ? (int) $row['duration_minutes'] : null,
            'chest_sensation' => $row['chest_sensation'],
            'nitroglycerin_taken' => $row['nitroglycerin_taken'] ? true : false,
            'medical_evaluation' => $row['medical_evaluation'],
            'trigger_context' => digest_clip($row['trigger_context']),
            'free_notes' => digest_clip($row['free_notes']),
        ];
    }
    log_ha_sync($pdo, 'incident_digest', 'success', 'day=' . $day . ' count=' . count($incidents));
    echo json_encode([
        'day' => $day,
        'timezone' => $tzName,
        'count' => count($incidents),
        'incidents_path' => 'incidents.php',
        'incidents' => $incidents,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    log_ha_sync($pdo, 'incident_digest', 'db_error', $e->getMessage());
    echo json_encode(['error' => 'db_error']);
}
