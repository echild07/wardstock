<?php
/**
 * LeeWard 6.5 common login — source of truth.
 * Serving copies: each hosted/web/inc/leeward_auth.php
 *
 * Session cookie name: leeward_sid (do not reuse PHPSESSID).
 * Products: staywhy, wattwhen, wardstock, beewell, wherewhen, leeidea.
 */

if (!defined('LEEWARD_PRODUCTS')) {
    define('LEEWARD_PRODUCTS', 'staywhy,wattwhen,wardstock,beewell,wherewhen,leeidea');
}

function leeward_products() {
    return explode(',', LEEWARD_PRODUCTS);
}

function leeward_client_ip_auth() {
    if (function_exists('leeward_client_ip')) {
        return leeward_client_ip();
    }
    $raw = trim(explode(',', (string) ($_SERVER['REMOTE_ADDR'] ?? ''))[0]);
    return filter_var($raw, FILTER_VALIDATE_IP) ? $raw : '';
}

function leeward_load_unified_config() {
    static $done = false;
    if ($done) {
        return defined('LEEWARD_DB_NAME') || defined('DB_NAME');
    }
    $done = true;
    $candidates = [
        dirname(__DIR__) . '/config/config.php',
        dirname(__DIR__, 2) . '/config/config.php',
        dirname(__DIR__, 3) . '/hosted/config/config.php',
    ];
    foreach ($candidates as $path) {
        if (is_file($path)) {
            require_once $path;
            break;
        }
    }
    return defined('LEEWARD_DB_NAME') || defined('DB_NAME');
}

function leeward_pdo() {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    leeward_load_unified_config();
    $host = defined('LEEWARD_DB_HOST') ? LEEWARD_DB_HOST : (defined('DB_HOST') ? DB_HOST : '');
    $name = defined('LEEWARD_DB_NAME') ? LEEWARD_DB_NAME : (defined('DB_NAME') ? DB_NAME : '');
    $user = defined('LEEWARD_DB_USER') ? LEEWARD_DB_USER : (defined('DB_USER') ? DB_USER : '');
    $pass = defined('LEEWARD_DB_PASS') ? LEEWARD_DB_PASS : (defined('DB_PASS') ? DB_PASS : '');
    if ($host === '' || $name === '') {
        return null;
    }
    $pdo = new PDO(
        'mysql:host=' . $host . ';dbname=' . $name . ';charset=utf8mb4',
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    return $pdo;
}

function leeward_start_session() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_name('leeward_sid');
    session_set_cookie_params([
        'lifetime' => 12 * 3600,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function leeward_current_user() {
    leeward_start_session();
    if (empty($_SESSION['leeward_user_id'])) {
        return null;
    }
    return [
        'id' => (int) $_SESSION['leeward_user_id'],
        'username' => (string) ($_SESSION['leeward_username'] ?? ''),
        'display_name' => (string) ($_SESSION['leeward_display'] ?? ''),
        'is_directory_admin' => !empty($_SESSION['leeward_directory_admin']),
        'products' => $_SESSION['leeward_products'] ?? [],
        'grants' => $_SESSION['leeward_grants'] ?? [],
    ];
}

function leeward_has_product($product) {
    $u = leeward_current_user();
    if (!$u) {
        return false;
    }
    if (!empty($u['is_directory_admin'])) {
        return true;
    }
    return in_array($product, $u['products'], true);
}

function leeward_has_grant($product, $grant) {
    $u = leeward_current_user();
    if (!$u) {
        return false;
    }
    if (!empty($u['is_directory_admin'])) {
        return true;
    }
    $key = $product . ':' . $grant;
    return in_array($key, $u['grants'], true);
}

function leeward_record_login($product, $ok, $username, $userId = null) {
    $pdo = leeward_pdo();
    if (!$pdo) {
        return;
    }
    $st = $pdo->prepare(
        'INSERT INTO leeward_login_event (user_id, username_tried, product, ok, ip, user_agent)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $ua = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 400);
    $st->execute([
        $userId,
        substr((string) $username, 0, 64),
        $product,
        $ok ? 1 : 0,
        leeward_client_ip_auth(),
        $ua,
    ]);
}

function leeward_load_user_row(PDO $pdo, $username) {
    $st = $pdo->prepare(
        'SELECT id, username, password_hash, display_name, active, must_change_password, is_directory_admin
         FROM leeward_user WHERE username = ? LIMIT 1'
    );
    $st->execute([$username]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function leeward_load_products_grants(PDO $pdo, $userId) {
    $st = $pdo->prepare('SELECT product FROM leeward_user_product WHERE user_id = ?');
    $st->execute([$userId]);
    $products = $st->fetchAll(PDO::FETCH_COLUMN);
    $st = $pdo->prepare('SELECT product, grant_key FROM leeward_user_grant WHERE user_id = ?');
    $st->execute([$userId]);
    $grants = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $g) {
        $grants[] = $g['product'] . ':' . $g['grant_key'];
    }
    return [$products, $grants];
}

function leeward_establish_session(array $row, array $products, array $grants) {
    leeward_start_session();
    session_regenerate_id(true);
    $_SESSION['leeward_user_id'] = (int) $row['id'];
    $_SESSION['leeward_username'] = $row['username'];
    $_SESSION['leeward_display'] = $row['display_name'] !== '' ? $row['display_name'] : $row['username'];
    $_SESSION['leeward_directory_admin'] = !empty($row['is_directory_admin']);
    $_SESSION['leeward_products'] = $products;
    $_SESSION['leeward_grants'] = $grants;
    $_SESSION['leeward_must_change'] = !empty($row['must_change_password']);
}

function leeward_try_login($product, $username, $password) {
    $pdo = leeward_pdo();
    if (!$pdo) {
        return [false, 'Directory database is not configured.'];
    }
    $row = leeward_load_user_row($pdo, $username);
    if (!$row || empty($row['active']) || !password_verify($password, $row['password_hash'])) {
        leeward_record_login($product, false, $username, $row['id'] ?? null);
        if (function_exists('leeward_log_visitor')) {
            leeward_log_visitor($product, 'login-fail');
        }
        return [false, 'Unknown user or password.'];
    }
    [$products, $grants] = leeward_load_products_grants($pdo, (int) $row['id']);
    $dir = !empty($row['is_directory_admin']);
    if (!$dir && !in_array($product, $products, true)) {
        leeward_record_login($product, false, $username, (int) $row['id']);
        if (function_exists('leeward_log_visitor')) {
            leeward_log_visitor($product, 'login-fail');
        }
        return [false, 'This login is not qualified for ' . $product . '.'];
    }
    leeward_establish_session($row, $products, $grants);
    $pdo->prepare(
        'UPDATE leeward_user SET last_login_at = UTC_TIMESTAMP(6), last_login_ip = ? WHERE id = ?'
    )->execute([leeward_client_ip_auth(), (int) $row['id']]);
    leeward_record_login($product, true, $username, (int) $row['id']);
    return [true, ''];
}

function leeward_require_product($product, $loginUrl = 'login.php') {
    if (leeward_has_product($product)) {
        return leeward_current_user();
    }
    header('Location: ' . $loginUrl);
    exit;
}

function leeward_require_directory_admin($loginUrl = 'login.php?mode=production') {
    $u = leeward_current_user();
    if ($u && !empty($u['is_directory_admin'])) {
        return $u;
    }
    header('Location: ' . $loginUrl);
    exit;
}

function leeward_logout() {
    leeward_start_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function leeward_bearer_ok($constantName = 'API_SYNC_TOKEN') {
    if (!defined($constantName) || constant($constantName) === '' || constant($constantName) === 'change_me') {
        return false;
    }
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($header === '' && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }
    if (!preg_match('/^Bearer\s+(.+)$/i', trim($header), $m)) {
        return false;
    }
    return hash_equals((string) constant($constantName), trim($m[1]));
}
