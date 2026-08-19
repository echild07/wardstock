<?php
// Lucius project — consolidated HA / Node-RED / Analytics status page
// (PLAN.md §15). Ward's stated goal: "is something wrong, and where" at
// a glance, from the one place that's always reachable, without needing
// to log into HA/Node-RED at all. Data comes from system_status_reports,
// upserted by the HA-side "Status Heartbeat" flow every 15 min via
// api/status_push.php — this page itself never talks to HA directly.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_login();

$pdo = get_db();
$active = 'status';

$rows = $pdo->query('SELECT * FROM system_status_reports ORDER BY category, component')->fetchAll();

$byCategory = ['ha' => [], 'nodered' => [], 'analytics' => []];
foreach ($rows as $r) {
    if (isset($byCategory[$r['category']])) {
        $byCategory[$r['category']][] = $r;
    }
}

// Overdue = elapsed time since last_run_at exceeds the component's own
// expected cadence plus a grace buffer. The buffer scales with the
// cadence itself (min 15 minutes) rather than a single fixed ratio —
// PLAN.md §15 only gave illustrative examples ("~4.5h" for a 4h
// schedule, "~20min" for a 15min one), not an exact formula, so this is
// a deliberate, reasonable approximation, not a value Ward specified.
function overdue_info($row) {
    if (!$row['expected_frequency_minutes'] || !$row['last_run_at']) {
        return null; // not schedule-based, or never reported — nothing to compute
    }
    $expected = (int)$row['expected_frequency_minutes'];
    $buffer = max(15, (int)round($expected * 0.25));
    $elapsedMin = (time() - strtotime($row['last_run_at'])) / 60;
    return [
        'elapsed_min' => $elapsedMin,
        'is_overdue' => $elapsedMin > ($expected + $buffer),
    ];
}

function fmt_component($c) {
    $labels = [
        'ha_core' => 'Home Assistant core (via Status Heartbeat)',
        'oura_sync' => 'Oura Sync (every 4h)',
        'godaddy_pull' => 'GoDaddy Pull (every 15min)',
        'bodycomp_import' => 'Body Composition Import (daily, ~noon)',
    ];
    return $labels[$c] ?? htmlspecialchars($c);
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>WardStock — System Status</title>
<link rel="manifest" href="manifest.json">
<link rel="icon" href="favicon-32.png">
<link rel="apple-touch-icon" href="apple-touch-icon.png">
<meta name="theme-color" content="#0f1216">
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="wrap">
  <header class="topbar">
    <h1>System Status</h1>
    <a class="btn-link" href="index.php">← Back to dashboard</a>
  </header>
  <?php include __DIR__ . '/partials_nav.php'; ?>

  <p class="hint">HA / Node-RED / Analytics, reported here every ~15 minutes by the Home Assistant piece's Status Heartbeat flow (PLAN.md §15). This page only reads what was last reported — it doesn't reach out to HA itself.</p>
  <p class="hint">Related: <a href="debug.php">Debug / Version →</a> · <a href="oura_test.php">Oura Connection Test →</a></p>

  <?php foreach ([
      'ha' => 'Home Assistant',
      'nodered' => 'Node-RED Flows',
      'analytics' => 'Analytics',
  ] as $cat => $catLabel): ?>
    <h3 class="section-label"><?= htmlspecialchars($catLabel) ?></h3>

    <?php if ($cat === 'analytics' && !$byCategory['analytics']): ?>
      <p class="empty">Nothing to report yet — this fills in once the Grafana/correlation-analysis phase exists (PLAN.md §11). Placeholder by design, not a bug.</p>
    <?php elseif (!$byCategory[$cat]): ?>
      <p class="empty">No reports received yet — either the Status Heartbeat flow hasn't run, or hasn't been built/deployed yet.</p>
    <?php else: ?>
      <table class="day-table">
        <tbody>
          <?php foreach ($byCategory[$cat] as $row): $od = overdue_info($row); ?>
            <tr>
              <td><?= fmt_component($row['component']) ?></td>
              <td>
                <?php if (!$row['last_run_at']): ?>
                  <span class="hint">never reported</span>
                <?php else: ?>
                  <code><?= htmlspecialchars($row['last_run_at']) ?></code> —
                  <?php if ($row['last_status'] === 'success'): ?>
                    ✅ success
                  <?php elseif ($row['last_status'] === 'failed'): ?>
                    ❌ failed
                  <?php else: ?>
                    <span class="hint"><?= htmlspecialchars($row['last_status'] ?? 'unknown') ?></span>
                  <?php endif; ?>
                  <?php if ($od && $od['is_overdue']): ?>
                    <span class="error">⚠ overdue — last ran <?= round($od['elapsed_min']) ?> min ago, expected every <?= (int)$row['expected_frequency_minutes'] ?> min</span>
                  <?php endif; ?>
                  <?php if ($row['last_status'] !== 'success' && $row['last_error']): ?>
                    <div class="hint"><?= htmlspecialchars($row['last_error']) ?></div>
                  <?php endif; ?>
                  <?php if ($row['detail']): ?>
                    <div class="hint"><?= htmlspecialchars($row['detail']) ?></div>
                  <?php endif; ?>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  <?php endforeach; ?>

  <p class="hint" style="margin-top:24px;">A <em>failed</em> run and a <em>missing/overdue</em> run are shown differently on purpose (PLAN.md §15) — a failure means it ran and something went wrong; overdue means it hasn't run when it should have, which is itself a distinct signal (a hung flow, HA being down, etc.).</p>
  <p class="hint">Per-endpoint call history: <a href="oura_test.php">Connection test</a>. Per-flow detail: <a href="oura_sync.php">HA Sync Status</a>.</p>
</div>
</body>
</html>
