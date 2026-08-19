<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/oura.php';
require_login();

$pdo = get_db();
$active = 'daily';

if (!oura_is_configured()) {
    header('Location: oura_connect.php');
    exit;
}

$tokens = oura_get_tokens($pdo);
$date = $_GET['date'] ?? date('Y-m-d');

// ---------- Actually perform the pull-and-populate ----------
$tokenExpired = false;
$noDataDiagnostics = null;
$fetchSuccess = null;
$fetchSuccessLogId = null;
if ($tokens && isset($_GET['fetch'])) {
    $validToken = oura_ensure_valid_token($pdo);
    if (!$validToken) {
        $tokenExpired = true;
    } else {
        $result = oura_fetch_day($pdo, $date);

        if ($result['mapped']) {
            // Same merge-safe pattern as import.php: only overwrite fields Oura
            // actually returned, preserve everything else already on that day
            // (Weight, Caffeine, Alcohol, Medications aren't Oura data at all).
            $logId = oura_upsert_daily_log($pdo, $date, $result['mapped']);

            // Shown directly on this page instead of an immediate silent
            // redirect — a fast redirect with no visible confirmation made
            // a successful pull look identical to nothing happening at all.
            $fetchSuccess = $result['mapped'];
            $fetchSuccessLogId = $logId;
        } else {
            $noData = true;
            $noDataDiagnostics = $result['diagnostics'];
        }
    }
}

// Refetch fresh — the fetch attempt above (if any) may have just updated
// last_success_at/last_attempt_at/last_attempt_ok, and the page should show
// that outcome now rather than what was true before this request started.
$tokens = oura_get_tokens($pdo);

// Ring hardware info, shown whenever connected (not tied to a date pull).
$ringResp = $tokens ? oura_api_get($pdo, 'ring_configuration') : null;

// Latest ha_sync_log row per endpoint — the Lucius project's HA piece
// (Node-RED) calls these on its own schedule; shown here so sync health
// is checkable from GoDaddy (always reachable) rather than only from
// HA's own dashboard (which may not be reachable away from home — see
// homeassistant/PLAN.md §7, §8).
$haLatestByEndpoint = [];
foreach (['oura_push', 'pull_manual_data', 'status', 'weight_push', 'status_push', 'get_shared_config'] as $ep) {
    $stmt = $pdo->prepare('SELECT * FROM ha_sync_log WHERE endpoint = ? ORDER BY called_at DESC LIMIT 1');
    $stmt->execute([$ep]);
    $haLatestByEndpoint[$ep] = $stmt->fetch();
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>WardStock — Pull from Oura</title>
<link rel="manifest" href="manifest.json">
<link rel="icon" href="favicon-32.png">
<link rel="apple-touch-icon" href="apple-touch-icon.png">
<meta name="theme-color" content="#0f1216">
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="wrap">
  <header class="topbar">
    <h1>Pull from Oura</h1>
    <a class="btn-link" href="daily.php">← Back to Daily Log</a>
  </header>
  <?php include __DIR__ . '/partials_nav.php'; ?>

  <h3 class="section-label">HA Sync Status <span class="hint">(Lucius project — Home Assistant piece)</span></h3>
  <table class="day-table">
    <tbody>
      <?php
        $haLabels = [
          'oura_push' => 'Oura data push (every 4h)',
          'pull_manual_data' => 'Manual data pull (every 15min)',
          'status' => 'Status check',
          'weight_push' => 'Body comp weight push (daily, noon)',
          'status_push' => 'Status heartbeat (every 15-30min)',
          'get_shared_config' => 'Shared Oura client ID/secret fetch',
        ];
        foreach ($haLabels as $ep => $label):
          $row = $haLatestByEndpoint[$ep];
      ?>
        <tr>
          <td><?= htmlspecialchars($label) ?></td>
          <td>
            <?php if (!$row): ?>
              <span class="hint">never called yet</span>
            <?php else: ?>
              <code><?= htmlspecialchars($row['called_at']) ?></code> —
              <?= $row['status_code'] === 'success' ? '✅ success' : '❌ ' . htmlspecialchars($row['status_code']) ?>
              <?php if ($row['status_code'] !== 'success' && $row['detail']): ?>
                <span class="hint">(<?= htmlspecialchars($row['detail']) ?>)</span>
              <?php endif; ?>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <p class="hint">Fuller history on <a href="oura_test.php">Connection test</a>. Full HA/Node-RED/Analytics view on <a href="status.php">System Status</a>.</p>

  <?php if (!$tokens): ?>
    <p class="hint">Not connected yet.</p>
    <p><a class="btn" href="oura_connect.php">Connect Oura</a></p>
  <?php elseif ($tokenExpired): ?>
    <p class="error">Your Oura connection has expired or was revoked and couldn't be refreshed.</p>
    <p><a class="btn" href="oura_connect.php">Reconnect</a> <a class="btn-link" href="oura_test.php">Run connection test</a></p>
  <?php else: ?>
    <?php if (!empty($fetchSuccess)): ?>
      <div class="report-box">
        <p class="notice notice-success">✓ Pulled from Oura and saved for <?= htmlspecialchars($date) ?>:</p>
        <ul class="med-change-list">
          <?php
            $labels = ['sleep_duration_hrs' => 'Sleep duration', 'sleep_efficiency' => 'Sleep efficiency', 'resting_hr' => 'Resting HR', 'hrv' => 'HRV', 'steps' => 'Steps'];
            foreach ($fetchSuccess as $k => $v):
          ?>
            <li><?= htmlspecialchars($labels[$k] ?? $k) ?>: <strong><?= htmlspecialchars($v) ?></strong></li>
          <?php endforeach; ?>
        </ul>
        <p><a class="btn" href="daily_form.php?id=<?= (int)$fetchSuccessLogId ?>&oura=<?= urlencode(implode(',', array_keys($fetchSuccess))) ?>">Continue to Daily Log entry →</a></p>
      </div>
    <?php endif; ?>

    <?php if ($ringResp && $ringResp['success'] && $ringResp['data']): ?>
      <h3 class="section-label">Your Ring</h3>
      <table class="day-table">
        <tbody>
          <?php foreach ($ringResp['data'] as $ring): ?>
            <tr><td>Model</td><td><code><?= htmlspecialchars($ring['hardware_type'] ?? 'unknown') ?></code></td></tr>
            <tr><td>Color / Design</td><td><code><?= htmlspecialchars(($ring['color'] ?? '?') . ' / ' . ($ring['design'] ?? '?')) ?></code></td></tr>
            <tr><td>Size</td><td><code><?= htmlspecialchars((string)($ring['size'] ?? '?')) ?></code></td></tr>
            <tr><td>Firmware</td><td><code><?= htmlspecialchars($ring['firmware_version'] ?? '?') ?></code></td></tr>
            <tr><td>Set up</td><td><code><?= htmlspecialchars($ring['set_up_at'] ?? '?') ?></code></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php if (count($ringResp['data']) > 1): ?>
        <p class="hint">Multiple rings found on this account — showing all of them above (only the currently active one syncs data).</p>
      <?php endif; ?>
    <?php elseif ($ringResp && $ringResp['success']): ?>
      <p class="hint">Connected, but no ring is registered on this Oura account yet.</p>
    <?php elseif ($ringResp): ?>
      <p class="hint">Couldn't load ring info — HTTP <?= htmlspecialchars((string)($ringResp['http_code'] ?? '?')) ?>, <?= htmlspecialchars($ringResp['error'] ?? 'unknown error') ?> (doesn't affect data pulls, just this display).
        <?php if ($ringResp['raw_body']): ?>
          <details style="margin-top:4px;"><summary class="hint" style="cursor:pointer;">Raw response</summary>
          <pre style="white-space:pre-wrap; word-break:break-all; background:#0d1015; padding:8px; border-radius:6px; font-size:0.75rem; margin-top:4px;"><?= htmlspecialchars($ringResp['raw_body']) ?></pre></details>
        <?php endif; ?>
      </p>
    <?php endif; ?>

    <?php if (!empty($noData)): ?>
      <p class="error">No Oura data found for <?= htmlspecialchars($date) ?> — nothing was saved.</p>
      <div class="report-box">
        <p class="hint">What actually happened for each endpoint:</p>
        <?php foreach (['sleep' => 'Sleep', 'daily_activity' => 'Daily Activity', 'daily_readiness' => 'Daily Readiness'] as $key => $label): $diag = $noDataDiagnostics[$key] ?? null; ?>
          <p>
            <strong><?= $label ?>:</strong>
            <?php if (!$diag): ?>
              <span class="hint">no data captured</span>
            <?php elseif (!$diag['success']): ?>
              <span class="error">❌ call failed — HTTP <?= htmlspecialchars((string)($diag['http_code'] ?? '?')) ?>, <?= htmlspecialchars($diag['error'] ?? 'unknown error') ?></span>
            <?php elseif (empty($diag['data'])): ?>
              <span class="notice">✅ call succeeded, but Oura returned zero records for this date</span>
            <?php elseif ($key === 'daily_readiness'): ?>
              <span class="notice">✅ <?= count($diag['data']) ?> record(s) — expected to show as "unmapped," WardStock never uses readiness fields, this endpoint is only fetched to check it responds</span>
            <?php else: ?>
              <span class="notice">✅ call succeeded, <?= count($diag['data']) ?> record(s) returned — but none had fields WardStock could map</span>
            <?php endif; ?>
            <?php if ($diag && $diag['raw_body']): ?>
              <details style="margin-top:4px;">
                <summary class="hint" style="cursor:pointer;">Raw response</summary>
                <pre style="white-space:pre-wrap; word-break:break-all; background:#0d1015; padding:8px; border-radius:6px; font-size:0.75rem; margin-top:4px;"><?= htmlspecialchars($diag['raw_body']) ?></pre>
              </details>
            <?php endif; ?>
          </p>
        <?php endforeach; ?>
        <p class="hint">
          "Call failed" points to a token/scope/config problem (see <a href="oura_test.php">Connection test</a>).
          "Succeeded, zero records" means Oura genuinely has nothing for that date yet — usually because the ring hasn't synced (opening the Oura app syncs sleep/readiness; activity syncs in the background), or that a sleep session got filed under a neighboring date (check the Daily Readiness raw response's <code>"day"</code> field above — if it's computed from a sleep session, comparing that date to what Sleep/Activity have can reveal an off-by-one issue).
        </p>
      </div>
    <?php endif; ?>
    <p class="hint">
      Last successful connection: <code><?= $tokens['last_success_at'] ? htmlspecialchars($tokens['last_success_at']) : 'never' ?></code>
      <?php if ($tokens['last_attempt_at']): ?>
        · Last attempt: <?= $tokens['last_attempt_ok'] ? '✅ succeeded' : '❌ failed' ?> (<?= htmlspecialchars($tokens['last_attempt_at']) ?>)
      <?php endif; ?>
    </p>
    <p class="hint">
      Pulls sleep duration, sleep efficiency, resting heart rate, HRV, and steps for the date below, and fills them into that day's Daily Log — you'll land on the form afterward to add Weight, Caffeine, Alcohol, and Medications, then save as usual. Existing values for anything else that day are left untouched.
    </p>
    <form method="get" action="oura_sync.php" class="incident-form">
      <input type="hidden" name="fetch" value="1">
      <fieldset>
        <legend>Date</legend>
        <label>Date <input type="date" name="date" value="<?= htmlspecialchars($date) ?>" required></label>
      </fieldset>
      <div class="form-actions">
        <button type="submit">Pull from Oura</button>
      </div>
    </form>
    <p class="hint" style="margin-top:20px;"><a href="oura_connect.php">Reconnect / switch Oura account</a> · <a href="oura_test.php">Connection test</a></p>
  <?php endif; ?>
</div>
</body>
</html>
