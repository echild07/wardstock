<?php
// Lucius project — Home Assistant piece calls this to check GoDaddy-side
// reachability and version sync (see homeassistant/PLAN.md §5, §7).
// Token-authenticated, NOT session-authenticated — no require_login().
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../oura.php';
require_once __DIR__ . '/../app_version.php';

header('Content-Type: application/json');
$pdo = get_db();

if (!check_api_token()) {
    http_response_code(401);
    log_ha_sync($pdo, 'status', 'auth_invalid', 'missing/invalid bearer token');
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

try {
    $dbVersion = get_setting($pdo, 'db_version');
    $inSync = ($dbVersion === APP_VERSION_SCHEMA);

    log_ha_sync($pdo, 'status', 'success');
    echo json_encode([
        'app_version' => APP_VERSION,
        'app_version_name' => APP_VERSION_NAME,
        'app_schema_version' => APP_VERSION_SCHEMA,
        'db_version' => $dbVersion,
        'version_in_sync' => $inSync,
        'server_time' => date('c'),
    ]);
} catch (Exception $e) {
    http_response_code(500);
    log_ha_sync($pdo, 'status', 'db_error', $e->getMessage());
    echo json_encode(['error' => 'server_error']);
}
