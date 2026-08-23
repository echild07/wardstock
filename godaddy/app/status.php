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
$active = 'wherewhen'; // moved under Where When (Fulgrim, PLAN.md §18)
$subActive = 'status';

$rows = $pdo->query('SELECT * FROM system_status_reports ORDER BY category, component')->fetchAll();

$byCategory = ['ha' => [], 'nodered' => [], 'analytics' => []];
foreach ($rows as $r) {
    if (isset($byCategory[$r['category']])) {
        $byCategory[$r['category']][] = $r;
    }
}

// overdue_info() now lives in db.php (shared with the attention-reminder
// banner on index.php, which uses the identical staleness check for the
// Oura-sync reminder — see attention.php).

function fmt_component($c) {
    $labels = [
        'ha_core' => 'Home Assistant core (via Status Heartbeat)',
        'oura_sync' => 'Oura Sync (every 4h)',
        'godaddy_pull' => 'GoDaddy Pull (every 15min)',
        'bodycomp_import' => 'Body Composition Import (daily, ~noon)',
        'medical_history_import' => 'Medical History Import (manual/on-demand)',
        'wherewhen_export' => 'wherewhen Data Export (manual + weekly Sun 3am)',
        'wherewhen_restore' => 'wherewhen Data Restore (manual/on-demand)',
        'analysis_engine' => 'wherewhen Analysis Engine (daily/weekly/monthly + manual all-data)',
        'godaddy_backup' => 'GoDaddy Backup — SQLite (daily 3am + manual)',
        'godaddy_restore' => 'GoDaddy Restore — from SQLite (manual/on-demand)',
        'godaddy_restore_from_file' => 'GoDaddy Restore — from export file (manual/on-demand)',
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
  <?php include __DIR__ . '/partials_wherewhen_nav.php'; ?>

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
                  <?php
                    // last_run_at is a naive MySQL DATETIME with no timezone
                    // marker — Node-RED writes it from new Date().toISOString()
                    // (always UTC), so the raw value really is UTC, just
                    // without anything saying so. Same gap this project hit
                    // and fixed for the wherewhen charts (Ward, Aug 2026:
                    // "it looks like their time is in UTC also, not user
                    // timezone") — display converts client-side to whatever
                    // timezone the browser is actually in, not a hardcoded
                    // one; see analysis.php's own <script> for the identical
                    // reasoning. Reformatted to an explicit ISO8601 UTC
                    // string (space -> "T", "Z" appended) so JS's Date
                    // constructor can't misread the naive string as local.
                    $utcIso = str_replace(' ', 'T', $row['last_run_at']) . 'Z';
                  ?>
                  <code class="js-local-time" data-utc="<?= htmlspecialchars($utcIso) ?>"><?= htmlspecialchars($row['last_run_at']) ?> UTC</code> —
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
<?php include __DIR__ . '/partials_footer.php'; ?>
<script>
// Converts every last_run_at timestamp to the browser's own local
// timezone (Ward, Aug 2026 — same reasoning as analysis.php's charts:
// the server doesn't know or guess the viewer's timezone, the browser
// just knows). data-utc is already an explicit UTC ISO string (built
// server-side from the naive DATETIME column); if JS never runs for any
// reason, the raw "... UTC" text already in the element is a correctly-
// labeled fallback, just less convenient than local time.
document.querySelectorAll('.js-local-time').forEach(function (el) {
    var d = new Date(el.dataset.utc);
    if (isNaN(d.getTime())) return; // leave the UTC fallback text as-is
    el.textContent = d.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
});
</script>
</body>
</html>
