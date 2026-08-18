<?php
// Fill these in with the values from your GoDaddy MySQL database (cPanel -> Databases).
define('DB_HOST', 'localhost');       // GoDaddy usually uses 'localhost'
define('DB_NAME', 'wardstock');
define('DB_USER', 'wardstock');
define('DB_PASS', '$Claudestock1010');

// A random secret used to sign the login session. Change this to any long random string.
define('APP_SECRET', 'ws-7f2ac91e4b8d3c60a5f9e2d1b4c8f7a3e6d0c9b2a5f8e1d4c7b0a3f6e9d2c5b8');

// Session lifetime in seconds (default: 12 hours)
define('SESSION_LIFETIME', 12 * 3600);

// Oura Ring integration (optional). Register a free developer application at
// https://cloud.ouraring.com/oauth/applications — the redirect URI you
// register there MUST exactly match OURA_REDIRECT_URI below (including
// https:// and the exact path). Leave CLIENT_ID blank to keep the feature
// hidden/disabled.
define('OURA_CLIENT_ID', '');
define('OURA_CLIENT_SECRET', '');
define('OURA_REDIRECT_URI', 'https://emperorschildren.net/Wardstock/oura_callback.php');

// Shared secret for the Lucius project's Home Assistant piece to authenticate
// against the api/*.php endpoints (bearer token, never the login password).
// Generated randomly — safe as-is, but change it if it's ever exposed
// (e.g. accidentally committed somewhere, or shared in a screenshot).
// Must match exactly what's configured in the Node-RED flow on the HA side.
define('API_SYNC_TOKEN', 'fb2ea1b748d6156001b5e5bf54bacc0f8873831e7392bd8cd257a40a3c71fec7');
