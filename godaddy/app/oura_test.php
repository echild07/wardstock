<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/oura.php';
require_login();

$pdo = get_db();
$active = 'daily';

function mask($value, $showStart = 4, $showEnd = 4) {
    if ($value === null || $value === '') return '(empty)';
    $len = strlen($value);
    if ($len <= $showStart + $showEnd) return str_repeat('•', $len);
    return substr($value, 0, $showStart) . str_repeat('•', max(4, $len - $showStart - $showEnd)) . substr($value, -$showEnd);
}

// ---------- Section 1: static configuration checks ----------
$checks = [];
$checks[] = ['Curl extension loaded', function_exists('curl_init'), function_exists('curl_init') ? 'OK' : 'MISSING — Oura calls cannot work at all without this. Contact GoDaddy support.'];
$checks[] = ['Served over HTTPS', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'), (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'OK' : 'This page loaded over plain HTTP — Oura requires HTTPS end to end.'];
$checks[] = ['OURA_CLIENT_ID set', OURA_CLIENT_ID !== '', OURA_CLIENT_ID !== '' ? mask(OURA_CLIENT_ID) : 'NOT SET — fill in config/config.php'];
$checks[] = ['OURA_CLIENT_SECRET set', OURA_CLIENT_SECRET !== '', OURA_CLIENT_SECRET !== '' ? '(set, ' . strlen(OURA_CLIENT_SECRET) . ' chars — never shown)' : 'NOT SET — fill in config/config.php'];
$checks[] = ['OURA_REDIRECT_URI', true, OURA_REDIRECT_URI];

// ---------- Section 2: connectivity test (can this server reach Oura at all?) ----------
$connectivity = null;
if (isset($_GET['test']) && $_GET['test'] === 'connectivity') {
    $ch = curl_init('https://api.ouraring.com/v2/usercollection/personal_info');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_NOBODY => false]);
    $body = curl_exec($ch);
    $connectivity = [
        'http_code' => curl_getinfo($ch, CURLINFO_HTTP_CODE),
        'curl_errno' => curl_errno($ch),
        'curl_error' => curl_error($ch),
        'body' => $body,
    ];
    curl_close($ch);
}

// ---------- Section 3: token presence (gates whether tests below can run) ----------
$tokens = oura_get_tokens($pdo);

// ---------- Section 4: live refresh test ----------
$refreshResult = null;
if (isset($_GET['test']) && $_GET['test'] === 'refresh' && $tokens) {
    $ch = curl_init('https://api.ouraring.com/oauth/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'refresh_token',
            'refresh_token' => $tokens['refresh_token'],
            'client_id' => OURA_CLIENT_ID,
            'client_secret' => OURA_CLIENT_SECRET,
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $body = curl_exec($ch);
    $refreshResult = [
        'http_code' => curl_getinfo($ch, CURLINFO_HTTP_CODE),
        'curl_errno' => curl_errno($ch),
        'curl_error' => curl_error($ch),
        'body' => $body,
    ];
    curl_close($ch);
    if ($refreshResult['http_code'] === 200) {
        $decoded = json_decode($body, true);
        if (isset($decoded['access_token'])) {
            oura_save_tokens($pdo, $decoded['access_token'], $decoded['refresh_token'], $decoded['expires_in']);
            $refreshResult['saved'] = true;
        }
    }
    oura_record_attempt($pdo, $refreshResult['http_code'] === 200);
}

// ---------- Section 5: live API call test (personal_info — lightest endpoint, minimal scope) ----------
$apiResult = null;
if (isset($_GET['test']) && $_GET['test'] === 'api_call' && $tokens) {
    $token = oura_ensure_valid_token($pdo);
    if (!$token) {
        $apiResult = ['error' => 'Could not get a valid access token (refresh failed) — run the refresh test above first.'];
    } else {
        $ch = curl_init('https://api.ouraring.com/v2/usercollection/personal_info');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        $body = curl_exec($ch);
        $apiResult = [
            'http_code' => curl_getinfo($ch, CURLINFO_HTTP_CODE),
            'curl_errno' => curl_errno($ch),
            'curl_error' => curl_error($ch),
            'body' => $body,
        ];
        curl_close($ch);
        oura_record_attempt($pdo, $apiResult['http_code'] === 200);
    }
}

// Recompute token status fresh here (not reused from Section 3 above) —
// the refresh/API tests just above may have updated last_success_at/
// last_attempt_at/last_attempt_ok, and the page should show that outcome
// immediately rather than the pre-test snapshot.
$tokens = oura_get_tokens($pdo);
$tokenStatus = null;
if ($tokens) {
    $expiresAt = strtotime($tokens['expires_at']);
    $tokenStatus = [
        'connected_at' => $tokens['connected_at'],
        'expires_at' => $tokens['expires_at'],
        'is_expired' => $expiresAt <= time(),
        'seconds_until_expiry' => $expiresAt - time(),
        'access_token_masked' => mask($tokens['access_token'], 6, 4),
        'refresh_token_masked' => mask($tokens['refresh_token'], 6, 4),
        'last_success_at' => $tokens['last_success_at'],
        'last_attempt_at' => $tokens['last_attempt_at'],
        'last_attempt_ok' => $tokens['last_attempt_ok'],
    ];
}

// ---------- Section 6: date range scan — one call per endpoint covering
// many days, to see which calendar days actually have records without
// guessing one date at a time. Also surfaces each record's own "day" field
// so a sleep session filed under a neighboring date (vs. the date you'd
// expect) becomes visible directly, rather than looking like "no data."
$rangeScan = null;
if (isset($_GET['test']) && $_GET['test'] === 'range_scan' && $tokens) {
    $rangeStart = $_GET['range_start'] ?? (new DateTime('-14 days'))->format('Y-m-d');
    $rangeEnd = $_GET['range_end'] ?? date('Y-m-d');
    $rangeScan = ['start' => $rangeStart, 'end' => $rangeEnd, 'endpoints' => []];
    foreach (['sleep' => 'Sleep', 'daily_activity' => 'Daily Activity', 'daily_readiness' => 'Daily Readiness'] as $key => $label) {
        $resp = oura_api_get($pdo, $key, ['start_date' => $rangeStart, 'end_date' => $rangeEnd]);
        $rangeScan['endpoints'][$key] = ['label' => $label, 'resp' => $resp];
    }
}

$defaultRangeStart = (new DateTime('-14 days'))->format('Y-m-d');
$defaultRangeEnd = date('Y-m-d');

$errorMeanings = [
    'invalid_client' => 'Client ID or Client Secret is wrong — recheck both against your Oura application page.',
    'invalid_grant' => 'The code or refresh token is expired, already used, or the redirect_uri doesn\'t match exactly what was used originally.',
    'invalid_token' => 'Access token expired, revoked, or malformed.',
    'unauthorized_client' => 'This application isn\'t authorized for this grant type — check the app is fully set up on Oura\'s side.',
    'invalid_scope' => 'Requested a scope that wasn\'t granted or doesn\'t exist.',
    'access_denied' => 'You denied the authorization request when connecting.',
];

// Fuller history than oura_sync.php's latest-per-endpoint view — for
// spotting a pattern of failures, not just the most recent result.
// See homeassistant/PLAN.md §7.
$haSyncHistory = $pdo->query('SELECT * FROM ha_sync_log ORDER BY called_at DESC LIMIT 50')->fetchAll();
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>WardStock — Oura Connection Test</title>
<link rel="manifest" href="manifest.json">
<link rel="icon" href="favicon-32.png">
<meta name="theme-color" content="#0f1216">
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="wrap">
  <header class="topbar">
    <h1>Oura Connection Test</h1>
    <a class="btn-link" href="oura_sync.php">← Back</a>
  </header>
  <?php include __DIR__ . '/partials_nav.php'; ?>

  <p class="hint">Diagnostic page — nothing here is sent anywhere except to Oura's own servers when you click a test button. If something's failing, copy the relevant section below and share it for help troubleshooting; access/refresh tokens and the client secret are masked so it's safe to paste.</p>

  <h3 class="section-label">1. Configuration</h3>
  <table class="day-table">
    <thead><tr><th>Check</th><th>Value</th></tr></thead>
    <tbody>
      <?php foreach ($checks as [$label, $ok, $value]): ?>
        <tr>
          <td><?= $ok ? '✅' : '❌' ?> <?= htmlspecialchars($label) ?></td>
          <td><code><?= htmlspecialchars($value) ?></code></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <h3 class="section-label">2. Server connectivity to Oura</h3>
  <p class="hint">Checks whether this server can reach api.ouraring.com at all — some shared hosts restrict outbound HTTPS, which would look identical to a credentials problem otherwise.</p>
  <p><a class="btn" href="oura_test.php?test=connectivity">Run connectivity test</a></p>
  <?php if ($connectivity): ?>
    <div class="report-box">
      <p><?= $connectivity['curl_errno'] === 0 ? '✅ Connection succeeded' : '❌ Connection failed at the network level' ?></p>
      <p>HTTP status: <code><?= (int)$connectivity['http_code'] ?></code> (401 here is actually a <strong>good sign</strong> — it means the server was reached, just without a token, which is expected for this test)</p>
      <?php if ($connectivity['curl_errno'] !== 0): ?>
        <p class="error">curl error #<?= $connectivity['curl_errno'] ?>: <?= htmlspecialchars($connectivity['curl_error']) ?></p>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <h3 class="section-label">3. Connection status</h3>
  <?php if (!$tokenStatus): ?>
    <p class="hint">Not connected. <a href="oura_connect.php">Connect now</a>.</p>
  <?php else: ?>
    <table class="day-table">
      <tbody>
        <tr><td>Connected since</td><td><code><?= htmlspecialchars($tokenStatus['connected_at']) ?></code></td></tr>
        <tr><td>Last successful connection</td><td><code><?= $tokenStatus['last_success_at'] ? htmlspecialchars($tokenStatus['last_success_at']) : 'never' ?></code></td></tr>
        <tr><td>Last attempt</td><td>
          <?php if (!$tokenStatus['last_attempt_at']): ?>
            <code>none recorded yet</code>
          <?php else: ?>
            <code><?= htmlspecialchars($tokenStatus['last_attempt_at']) ?></code> — <?= $tokenStatus['last_attempt_ok'] ? '✅ succeeded' : '❌ failed' ?>
          <?php endif; ?>
        </td></tr>
        <tr><td>Access token expires</td><td><code><?= htmlspecialchars($tokenStatus['expires_at']) ?></code> — <?= $tokenStatus['is_expired'] ? '❌ expired' : '✅ valid for ' . round($tokenStatus['seconds_until_expiry'] / 60) . ' more min' ?></td></tr>
        <tr><td>Access token</td><td><code><?= htmlspecialchars($tokenStatus['access_token_masked']) ?></code></td></tr>
        <tr><td>Refresh token</td><td><code><?= htmlspecialchars($tokenStatus['refresh_token_masked']) ?></code></td></tr>
      </tbody>
    </table>

    <h3 class="section-label">4. Test token refresh</h3>
    <p><a class="btn" href="oura_test.php?test=refresh">Run refresh test</a></p>
    <?php if ($refreshResult): ?>
      <div class="report-box">
        <p>HTTP status: <code><?= (int)$refreshResult['http_code'] ?></code> <?= $refreshResult['http_code'] === 200 ? '✅' : '❌' ?></p>
        <?php if ($refreshResult['curl_errno']): ?><p class="error">curl error: <?= htmlspecialchars($refreshResult['curl_error']) ?></p><?php endif; ?>
        <?php if (!empty($refreshResult['saved'])): ?><p class="notice notice-success">✓ New tokens saved.</p><?php endif; ?>
        <p>Raw response:</p>
        <pre style="white-space:pre-wrap; word-break:break-all; background:#0d1015; padding:10px; border-radius:8px; font-size:0.8rem;"><?= htmlspecialchars($refreshResult['body'] ?? '(empty)') ?></pre>
        <?php
          $decoded = json_decode($refreshResult['body'] ?? '', true);
          if (isset($decoded['error']) && isset($errorMeanings[$decoded['error']])):
        ?>
          <p class="error">Meaning: <?= htmlspecialchars($errorMeanings[$decoded['error']]) ?></p>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <h3 class="section-label">5. Test a real API call</h3>
    <p class="hint">Calls <code>/v2/usercollection/personal_info</code> — the lightest endpoint, needs no special scope.</p>
    <p><a class="btn" href="oura_test.php?test=api_call">Run API call test</a></p>
    <?php if ($apiResult): ?>
      <div class="report-box">
        <?php if (isset($apiResult['error'])): ?>
          <p class="error"><?= htmlspecialchars($apiResult['error']) ?></p>
        <?php else: ?>
          <p>HTTP status: <code><?= (int)$apiResult['http_code'] ?></code> <?= $apiResult['http_code'] === 200 ? '✅' : '❌' ?></p>
          <?php if ($apiResult['curl_errno']): ?><p class="error">curl error: <?= htmlspecialchars($apiResult['curl_error']) ?></p><?php endif; ?>
          <p>Raw response:</p>
          <pre style="white-space:pre-wrap; word-break:break-all; background:#0d1015; padding:10px; border-radius:8px; font-size:0.8rem;"><?= htmlspecialchars($apiResult['body'] ?? '(empty)') ?></pre>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <h3 class="section-label">6. Scan a date range</h3>
    <p class="hint">One call per endpoint covering the whole range — shows which calendar days actually have records, and each record's own <code>"day"</code> field, so a session filed under a neighboring date (rather than the one you expected) becomes visible directly instead of just looking like "no data."</p>
    <form method="get" action="oura_test.php" class="incident-form">
      <input type="hidden" name="test" value="range_scan">
      <div class="grid3">
        <label>From <input type="date" name="range_start" value="<?= htmlspecialchars($rangeScan['start'] ?? $defaultRangeStart) ?>"></label>
        <label>To <input type="date" name="range_end" value="<?= htmlspecialchars($rangeScan['end'] ?? $defaultRangeEnd) ?>"></label>
      </div>
      <div class="form-actions"><button type="submit">Scan range</button></div>
    </form>
    <?php if ($rangeScan): ?>
      <?php foreach ($rangeScan['endpoints'] as $key => $ep): $resp = $ep['resp']; ?>
        <div class="report-box">
          <p><strong><?= htmlspecialchars($ep['label']) ?></strong> —
            <?php if (!$resp['success']): ?>
              <span class="error">❌ call failed — HTTP <?= htmlspecialchars((string)($resp['http_code'] ?? '?')) ?>, <?= htmlspecialchars($resp['error'] ?? 'unknown error') ?></span>
            <?php elseif (empty($resp['data'])): ?>
              <span class="notice">zero records across the whole range <?= htmlspecialchars($rangeScan['start']) ?> to <?= htmlspecialchars($rangeScan['end']) ?></span>
            <?php else: ?>
              <span class="notice">✅ <?= count($resp['data']) ?> record(s) — days found: <code><?= htmlspecialchars(implode(', ', array_map(fn($r) => $r['day'] ?? '?', $resp['data']))) ?></code></span>
            <?php endif; ?>
          </p>
          <?php if ($resp['raw_body']): ?>
            <details>
              <summary class="hint" style="cursor:pointer;">Raw response</summary>
              <pre style="white-space:pre-wrap; word-break:break-all; background:#0d1015; padding:8px; border-radius:6px; font-size:0.75rem; margin-top:4px;"><?= htmlspecialchars($resp['raw_body']) ?></pre>
            </details>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  <?php endif; ?>

  <h3 class="section-label">HA Sync Log — last 50 calls <span class="hint">(Lucius project — Home Assistant piece)</span></h3>
  <?php if (!$haSyncHistory): ?>
    <p class="hint">No calls logged yet.</p>
  <?php else: ?>
    <table class="day-table">
      <thead><tr><th>When</th><th>Endpoint</th><th>Result</th><th>Detail</th></tr></thead>
      <tbody>
        <?php foreach ($haSyncHistory as $row): ?>
          <tr>
            <td><code><?= htmlspecialchars($row['called_at']) ?></code></td>
            <td><?= htmlspecialchars($row['endpoint']) ?></td>
            <td><?= $row['status_code'] === 'success' ? '✅' : '❌ ' . htmlspecialchars($row['status_code']) ?></td>
            <td><span class="hint"><?= htmlspecialchars($row['detail'] ?? '') ?></span></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
</body>
</html>
