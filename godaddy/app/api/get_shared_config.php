<?php
// Lucius project — Home Assistant piece calls this to fetch the values
// that used to be manually hand-copied into lucius_secrets.json and kept
// in sync by hand (see homeassistant/PLAN.md §3). GoDaddy's config.php
// is now the single source of truth for these two values; HA fetches
// them live each relevant flow run instead of storing its own copy.
//
// Deliberately does NOT return DB credentials, APP_SECRET, or
// API_SYNC_TOKEN itself — API_SYNC_TOKEN remains the one unavoidable
// bootstrap secret HA must already have to authenticate this very call
// (a token can't hand out the credential that authenticates fetching it).
// Everything GoDaddy-only stays GoDaddy-only; this endpoint only narrows
// what used to require manual duplication down to that one bootstrap value.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth.php';

header('Content-Type: application/json');
$pdo = get_db();

if (!check_api_token()) {
    http_response_code(401);
    log_ha_sync($pdo, 'get_shared_config', 'auth_invalid', 'missing/invalid bearer token');
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

log_ha_sync($pdo, 'get_shared_config', 'success');
echo json_encode([
    'oura_client_id' => OURA_CLIENT_ID,
    'oura_client_secret' => OURA_CLIENT_SECRET,
    // Settings page (Ward, Aug 2026) — the analysis engine's day-boundary
    // grouping used to hardcode America/New_York; now it fetches this
    // fresh each run and falls back to that same default if unset/
    // unreachable. get_setting() returning null (never saved yet) is the
    // normal case for an install that hasn't visited settings.php.
    'preferred_timezone' => get_setting($pdo, 'preferred_timezone') ?: 'America/New_York',
]);
