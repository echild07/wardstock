<?php
/**
 * LeeWard visitor IP log — source of truth.
 *
 * Serving copies live in each product's app folder (and the portal
 * `inc/`). Keep those copies identical to this file.
 *
 * standwhy owns the MySQL table `staywhy_visitor_ips`. Sibling apps
 * must NOT use their own get_db() (that is a different database).
 * They load `staywhy/config/visitor_db.php` (LEEWARD_VISITOR_DB_*).
 * standwhy pages pass get_db() as the third argument.
 *
 * Failures are swallowed so a missing table/config never takes a
 * public page down. Refresh of the same IP updates last_seen only.
 */

function leeward_client_ip() {
    $raw = '';
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $raw = $_SERVER['HTTP_CF_CONNECTING_IP'];
    } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
        $raw = $_SERVER['REMOTE_ADDR'];
    }
    $raw = trim(explode(',', (string)$raw)[0]);
    if ($raw === '') {
        return '';
    }
    return filter_var($raw, FILTER_VALIDATE_IP) ? $raw : '';
}

function leeward_visitor_config_paths() {
    $paths = [];
    $doc = rtrim(str_replace('\\', '/', (string)($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
    if ($doc !== '') {
        $paths[] = $doc . '/staywhy/config/visitor_db.php';
    }
    $here = str_replace('\\', '/', __DIR__);
    $paths[] = $here . '/config/visitor_db.php';
    $paths[] = $here . '/../config/visitor_db.php';
    $paths[] = $here . '/../staywhy/config/visitor_db.php';
    $paths[] = dirname($here) . '/staywhy/config/visitor_db.php';
    return $paths;
}

function leeward_visitor_connect() {
    $host = defined('LEEWARD_VISITOR_DB_HOST') ? LEEWARD_VISITOR_DB_HOST : 'localhost';
    $pass = defined('LEEWARD_VISITOR_DB_PASS') ? LEEWARD_VISITOR_DB_PASS : '';
    $dsn = 'mysql:host=' . $host . ';dbname=' . LEEWARD_VISITOR_DB_NAME . ';charset=utf8mb4';
    return new PDO($dsn, LEEWARD_VISITOR_DB_USER, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function leeward_visitor_pdo() {
    if (defined('LEEWARD_VISITOR_DB_NAME') && defined('LEEWARD_VISITOR_DB_USER')) {
        return leeward_visitor_connect();
    }
    foreach (leeward_visitor_config_paths() as $f) {
        if (is_file($f)) {
            require_once $f;
            break;
        }
    }
    if (!defined('LEEWARD_VISITOR_DB_NAME') || !defined('LEEWARD_VISITOR_DB_USER')) {
        return null;
    }
    return leeward_visitor_connect();
}

/**
 * Upsert one row per IP. $pdo is only for standwhy (same DB as the app).
 */
function leeward_log_visitor($product, $page, $pdo = null) {
    try {
        $ip = leeward_client_ip();
        if ($ip === '') {
            return;
        }
        if ($pdo === null) {
            $pdo = leeward_visitor_pdo();
        }
        if (!$pdo) {
            return;
        }
        $product = substr((string)$product, 0, 32);
        $page = substr((string)$page, 0, 80);
        $stmt = $pdo->prepare(
            'INSERT INTO staywhy_visitor_ips (ip, first_seen, last_seen, last_product, last_page)
             VALUES (?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), ?, ?)
             ON DUPLICATE KEY UPDATE
               last_seen = UTC_TIMESTAMP(),
               last_product = VALUES(last_product),
               last_page = VALUES(last_page)'
        );
        $stmt->execute([$ip, $product, $page]);
    } catch (Throwable $e) {
        error_log('leeward_log_visitor: ' . $e->getMessage());
    }
}
