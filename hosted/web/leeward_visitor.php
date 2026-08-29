<?php
/**
 * LeeWard visitor IP log — serving copy of leeward/tools/php/visitor_log.php.
 * Keep identical to that file.
 *
 * standwhy owns the MySQL table `staywhy_visitor_ips`. Sibling apps
 * must NOT use their own get_db() (that is a different database).
 * They load `staywhy/config/visitor_db.php` (LEEWARD_VISITOR_DB_*).
 * standwhy pages pass get_db() as the third argument.
 *
 * Failures are swallowed so a missing table/config never takes a
 * public page down. Refresh of the same IP updates last_seen only.
 *
 * Extra fields (29 Aug 2026): last User-Agent, device class, signal
 * (human / crawler / scanner / empty_ua), optional JS screen size,
 * hit_count, fail_count. Login pages call leeward_log_visitor on
 * GET; call it again with page login-fail after a bad password.
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

function leeward_visitor_ua() {
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? trim((string)$_SERVER['HTTP_USER_AGENT']) : '';
    if (function_exists('mb_substr')) {
        return mb_substr($ua, 0, 400);
    }
    return substr($ua, 0, 400);
}

/**
 * Best-effort class from User-Agent + Client Hints. Not ground truth —
 * anyone can spoof a UA. Good enough to split phones from laptops and
 * obvious crawlers/scanners from browsers.
 *
 * @return array{device:string,signal:string}
 */
function leeward_classify_visitor($ua) {
    $ua = (string)$ua;
    $low = strtolower($ua);
    if ($ua === '') {
        return ['device' => 'unknown', 'signal' => 'empty_ua'];
    }
    $scanners = [
        'sqlmap', 'nikto', 'nmap', 'masscan', 'zgrab', 'nuclei', 'wpscan',
        'gobuster', 'dirbuster', 'dirb/', 'nessus', 'openvas', 'hydra',
        'acunetix', 'w3af',
    ];
    foreach ($scanners as $tok) {
        if (strpos($low, $tok) !== false) {
            return ['device' => 'scanner', 'signal' => 'scanner'];
        }
    }
    $crawlers = [
        'googlebot', 'bingbot', 'bingpreview', 'slurp', 'duckduckbot',
        'baiduspider', 'yandex', 'facebookexternalhit', 'twitterbot',
        'linkedinbot', 'applebot', 'gptbot', 'claudebot', 'anthropic',
        'bytespider', 'ahrefs', 'semrush', 'mj12bot', 'dotbot', 'petalbot',
        'ia_archiver', 'wget/', 'curl/', 'python-requests', 'python-urllib',
        'go-http-client', 'libwww', 'httpie', 'okhttp', 'scrapy',
        'headlesschrome', 'playwright', 'puppeteer', 'phantomjs',
        'bot/', 'bot;', ' spider', 'crawler',
    ];
    foreach ($crawlers as $tok) {
        if (strpos($low, $tok) !== false) {
            return ['device' => 'bot', 'signal' => 'crawler'];
        }
    }
    $chMobile = isset($_SERVER['HTTP_SEC_CH_UA_MOBILE']) ? (string)$_SERVER['HTTP_SEC_CH_UA_MOBILE'] : '';
    if (strpos($low, 'ipad') !== false || (strpos($low, 'tablet') !== false && strpos($low, 'mobile') === false)) {
        return ['device' => 'tablet', 'signal' => 'human'];
    }
    if (strpos($low, 'iphone') !== false || strpos($low, 'ipod') !== false || strpos($low, 'windows phone') !== false) {
        return ['device' => 'phone', 'signal' => 'human'];
    }
    if (strpos($low, 'android') !== false) {
        if (strpos($low, 'mobile') !== false || $chMobile === '?1') {
            return ['device' => 'phone', 'signal' => 'human'];
        }
        return ['device' => 'tablet', 'signal' => 'human'];
    }
    if ($chMobile === '?1') {
        return ['device' => 'phone', 'signal' => 'human'];
    }
    if (
        strpos($low, 'windows nt') !== false
        || strpos($low, 'macintosh') !== false
        || strpos($low, 'x11') !== false
        || strpos($low, 'cros') !== false
    ) {
        return ['device' => 'desktop', 'signal' => 'human'];
    }
    return ['device' => 'unknown', 'signal' => 'human'];
}

function leeward_visitor_ensure_columns($pdo) {
    static $done = false;
    if ($done || !$pdo) {
        return;
    }
    $done = true;
    try {
        $have = $pdo->query(
            "SELECT COLUMN_NAME FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staywhy_visitor_ips'"
        )->fetchAll(PDO::FETCH_COLUMN);
        $have = array_map('strval', $have ?: []);
        $want = [
            'last_user_agent' => 'VARCHAR(400) NULL',
            'last_device' => 'VARCHAR(24) NULL',
            'last_signal' => 'VARCHAR(24) NULL',
            'last_screen' => 'VARCHAR(32) NULL',
            'hit_count' => 'INT NOT NULL DEFAULT 1',
            'fail_count' => 'INT NOT NULL DEFAULT 0',
        ];
        foreach ($want as $col => $ddl) {
            if (!in_array($col, $have, true)) {
                $pdo->exec('ALTER TABLE staywhy_visitor_ips ADD COLUMN `' . $col . '` ' . $ddl);
            }
        }
    } catch (Throwable $e) {
        error_log('leeward_visitor_ensure_columns: ' . $e->getMessage());
    }
}

/**
 * Upsert one row per IP. $pdo is only for standwhy (same DB as the app).
 * Use page "login-fail" after a rejected password (never store the password).
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
        leeward_visitor_ensure_columns($pdo);
        $product = substr((string)$product, 0, 32);
        $page = substr((string)$page, 0, 80);
        $ua = leeward_visitor_ua();
        $cls = leeward_classify_visitor($ua);
        $fail = ($page === 'login-fail') ? 1 : 0;
        $stmt = $pdo->prepare(
            'INSERT INTO staywhy_visitor_ips
               (ip, first_seen, last_seen, last_product, last_page,
                last_user_agent, last_device, last_signal, hit_count, fail_count)
             VALUES (?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), ?, ?, ?, ?, ?, 1, ?)
             ON DUPLICATE KEY UPDATE
               last_seen = UTC_TIMESTAMP(),
               last_product = VALUES(last_product),
               last_page = VALUES(last_page),
               last_user_agent = VALUES(last_user_agent),
               last_device = VALUES(last_device),
               last_signal = VALUES(last_signal),
               hit_count = hit_count + 1,
               fail_count = fail_count + VALUES(fail_count)'
        );
        $stmt->execute([$ip, $product, $page, $ua !== '' ? $ua : null, $cls['device'], $cls['signal'], $fail]);
    } catch (Throwable $e) {
        error_log('leeward_log_visitor: ' . $e->getMessage());
        try {
            if (!empty($pdo) && !empty($ip)) {
                $stmt = $pdo->prepare(
                    'INSERT INTO staywhy_visitor_ips (ip, first_seen, last_seen, last_product, last_page)
                     VALUES (?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), ?, ?)
                     ON DUPLICATE KEY UPDATE
                       last_seen = UTC_TIMESTAMP(),
                       last_product = VALUES(last_product),
                       last_page = VALUES(last_page)'
                );
                $stmt->execute([$ip, $product, $page]);
            }
        } catch (Throwable $e2) {
            error_log('leeward_log_visitor fallback: ' . $e2->getMessage());
        }
    }
}

/** Screen size is not in HTTP. Login pages echo this once; staywhy stores it. */
function leeward_visitor_hint_script() {
    return '<script>(function(){try{var b=new URLSearchParams({w:String(screen.width),h:String(screen.height),dpr:String(window.devicePixelRatio||1)});'
        . 'var u="/staywhy/api/visitor_hint.php";'
        . 'if(navigator.sendBeacon){navigator.sendBeacon(u,b);}else{fetch(u,{method:"POST",body:b,keepalive:true,credentials:"omit"});}'
        . '}catch(e){}})();</script>';
}

function leeward_visitor_save_screen($w, $h, $dpr, $pdo = null) {
    try {
        $ip = leeward_client_ip();
        if ($ip === '') {
            return;
        }
        $w = (int)$w;
        $h = (int)$h;
        if ($w < 50 || $w > 16000 || $h < 50 || $h > 16000) {
            return;
        }
        $dpr = (float)$dpr;
        if ($dpr <= 0 || $dpr > 8) {
            $dpr = 1;
        }
        $screen = $w . 'x' . $h . '@' . rtrim(rtrim(number_format($dpr, 2, '.', ''), '0'), '.');
        if ($pdo === null) {
            $pdo = leeward_visitor_pdo();
        }
        if (!$pdo) {
            return;
        }
        leeward_visitor_ensure_columns($pdo);
        $stmt = $pdo->prepare('UPDATE staywhy_visitor_ips SET last_screen = ? WHERE ip = ?');
        $stmt->execute([substr($screen, 0, 32), $ip]);
    } catch (Throwable $e) {
        error_log('leeward_visitor_save_screen: ' . $e->getMessage());
    }
}
