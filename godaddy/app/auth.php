<?php
require_once __DIR__ . '/config/config.php';

function start_session() {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => SESSION_LIFETIME,
            'path' => '/',
            'secure' => true,      // requires HTTPS (GoDaddy gives you free SSL — use it)
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

function is_logged_in() {
    start_session();
    return !empty($_SESSION['user_id']);
}

function require_login() {
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

// For the api/*.php endpoints only — machine-to-machine auth via a bearer
// token (API_SYNC_TOKEN in config.php), completely separate from the human
// session-cookie login. Never accepts the login password. On failure, sends
// a 401 and returns false rather than exiting directly, so the caller can
// log the failed attempt to ha_sync_log before ending the request.
function check_api_token() {
    if (!defined('API_SYNC_TOKEN') || API_SYNC_TOKEN === '') {
        return false;
    }
    // The Authorization header is notoriously unreliable to read from PHP
    // on shared hosting — a common Apache/FastCGI quirk strips it from
    // $_SERVER by default on a lot of hosts, GoDaddy included on at least
    // some configurations (confirmed live: correct token, reachable
    // server, still rejected — the header just wasn't arriving). The
    // .htaccess rewrite rule (RewriteCond %{HTTP:Authorization}) is the
    // primary fix and should populate HTTP_AUTHORIZATION directly; these
    // are defensive fallbacks in case that alone isn't enough on every
    // possible hosting configuration.
    $header = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';
    if (!$header && function_exists('getallheaders')) {
        $headers = getallheaders();
        $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }
    if (!$header && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }
    if (!preg_match('/^Bearer\s+(.+)$/i', trim($header), $m)) {
        return false;
    }
    return hash_equals(API_SYNC_TOKEN, $m[1]);
}
