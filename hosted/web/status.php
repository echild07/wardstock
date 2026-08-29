<?php
// WardStock system status (PLAN.md §15). The on-prem box is WOS now —
// Home Assistant was retired (that Pi is 192.168.4.29). This page only
// reads wardstock_system_status_reports, upserted every 15 min by the
// WardStock orchestrator via api/status_push.php.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_login();

$pdo = get_db();
$active = 'wherewhen'; // moved under Where When (Fulgrim, PLAN.md §18)
$subActive = 'status';

$rows = $pdo->query('SELECT * FROM wardstock_system_status_reports ORDER BY category, component')->fetchAll();

// `ha` leftover rows (ha_core from the retired Home Assistant box, or a
// brief mis-aimed WOS heartbeat) are not shown. There is no delete API
// for this table — phpMyAdmin if you want them gone. Category `wos` is
// the live processing-box signal.
$byCategory = ['wos' => [], 'nodered' => [], 'analytics' => []];
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
        'wos' => 'WOS processing (192.168.4.29)',
        'oura_sync' => 'Oura Sync (every 4h)',
        'godaddy_pull' => 'GoDaddy Pull (every 15min)',
        'bodycomp_import' => 'Body Composition Import (daily, ~noon)',
        'medical_history_import' => 'Medical History Import (manual/on-demand)',
        'wherewhen_export' => 'wherewhen Data Export (nightly via full_sync + manual)',
        'wherewhen_restore' => 'wherewhen Data Restore (manual/on-demand)',
        'analysis_engine' => 'wherewhen Analysis Engine (daily/weekly/monthly + manual all-data)',
        'godaddy_backup' => 'GoDaddy Backup — SQLite (daily 3am + manual)',
        'godaddy_restore' => 'GoDaddy Restore — from SQLite (manual/on-demand)',
        'godaddy_restore_from_file' => 'GoDaddy Restore — from export file (manual/on-demand)',
        'incident_digest' => 'Incident digest email (21:00)',
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

  <p class="hint">WOS / on-prem jobs / analytics, reported here every ~15 minutes by the WardStock orchestrator on WOS. This page only reads what was last pushed — it does not reach out to the box. Home Assistant was retired (that Pi is WOS); leftover <code>ha</code> MySQL rows are hidden here. There is no delete API for those rows — phpMyAdmin if you want them gone.</p>
  <p class="hint">Related: <a href="debug.php">Debug / Version →</a> · <a href="oura_test.php">Oura Connection Test →</a></p>

  <?php foreach ([
      'wos' => 'WOS',
      'nodered' => 'On-prem jobs',
      'analytics' => 'Analytics',
  ] as $cat => $catLabel): ?>
    <h3 class="section-label"><?= htmlspecialchars($catLabel) ?></h3>

    <?php if ($cat === 'analytics' && !$byCategory['analytics']): ?>
      <p class="empty">Nothing to report yet under this heading — analysis results live with the on-prem jobs above (analysis_engine).</p>
    <?php elseif (!$byCategory[$cat]): ?>
      <p class="empty">No reports received yet — the WardStock orchestrator heartbeat has not pushed this category.</p>
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

  <p class="hint" style="margin-top:24px;">A <em>failed</em> run and a <em>missing/overdue</em> run are shown differently on purpose (PLAN.md §15) — a failure means it ran and something went wrong; overdue means it hasn't run when it should have (hung job, WOS down, etc.).</p>
  <p class="hint">Per-endpoint call history: <a href="oura_test.php">Connection test</a>. Per-job Oura detail: <a href="oura_sync.php">Oura sync log</a>.</p>
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
