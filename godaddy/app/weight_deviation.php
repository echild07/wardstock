<?php
// New page (PLAN.md §16, GoDaddy piece): bar chart of weight over a
// selectable range, framed as deviation from THAT RANGE'S OWN average —
// zero baseline is the range's average, recalculated per range (not a
// fixed global average), bars extend up above it or down below it.
// Ward's specific, deliberate framing request — not a generic weight chart.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_login();

$pdo = get_db();
$active = 'daily';

$allowedRanges = [7, 30, 90];
$range = (int)($_GET['range'] ?? 30);
if (!in_array($range, $allowedRanges, true)) {
    $range = 30;
}

$startDate = app_now($pdo)->modify("-$range days")->format('Y-m-d'); // was new DateTime('today') — server default, not Ward's actual today (Aug 2026 fix)
$stmt = $pdo->prepare('SELECT log_date, weight FROM daily_logs WHERE log_date >= ? AND weight IS NOT NULL ORDER BY log_date');
$stmt->execute([$startDate]);
$points = $stmt->fetchAll();

function fmt_lbs($v) { return rtrim(rtrim(number_format((float)$v, 1), '0'), '.'); }

$avg = null;
$labels = [];
$deviations = [];
$absoluteWeights = [];
if ($points) {
    $weights = array_map(fn($p) => (float)$p['weight'], $points);
    $avg = array_sum($weights) / count($weights);
    foreach ($points as $p) {
        $labels[] = date('M j', strtotime($p['log_date']));
        $absoluteWeights[] = (float)$p['weight'];
        $deviations[] = round((float)$p['weight'] - $avg, 1);
    }
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>WardStock — Weight Deviation</title>
<link rel="manifest" href="manifest.json">
<link rel="icon" href="favicon-32.png">
<link rel="apple-touch-icon" href="apple-touch-icon.png">
<meta name="theme-color" content="#0f1216">
<link rel="stylesheet" href="style.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>
</head>
<body>
<div class="wrap">
  <header class="topbar">
    <h1>Weight Deviation</h1>
    <a class="btn-link" href="weight_trend.php">← Back to Weight Trend</a>
  </header>
  <?php include __DIR__ . '/partials_nav.php'; ?>

  <p class="hint">Bars show how far each day's weight is from the <strong>average of the days shown</strong> — not a fixed target. Switching the range changes what counts as "average," and therefore what counts as above/below.</p>

  <div class="range-tabs" style="display:flex; gap:8px; margin: 12px 0 18px;">
    <?php foreach ($allowedRanges as $r): ?>
      <a href="?range=<?= $r ?>" class="btn-link<?= $r === $range ? ' active' : '' ?>" style="padding:6px 14px; border:1px solid var(--border); border-radius:8px; <?= $r === $range ? 'background:var(--accent); color:#fff; border-color:var(--accent);' : '' ?>">Last <?= $r ?> days</a>
    <?php endforeach; ?>
  </div>

  <?php if (count($points) < 2): ?>
    <p class="empty">Not enough weight entries in the last <?= $range ?> days to chart (<?= count($points) ?> so far — need at least 2).</p>
  <?php else: ?>
    <div class="report-box">
      <canvas id="deviationChart" height="110"></canvas>
    </div>
    <div class="report-stats">
      <div class="report-stat"><span class="report-num"><?= fmt_lbs($avg) ?></span><span class="report-label">Average over last <?= $range ?> days</span></div>
      <div class="report-stat"><span class="report-num"><?= count($points) ?></span><span class="report-label">Entries in range</span></div>
      <div class="report-stat"><span class="report-num"><?= fmt_lbs(end($absoluteWeights)) ?></span><span class="report-label">Most recent (<?= end($labels) ?>)</span></div>
    </div>

    <script>
      const labels = <?= json_encode($labels) ?>;
      const deviations = <?= json_encode($deviations) ?>;
      const absoluteWeights = <?= json_encode($absoluteWeights) ?>;
      const avg = <?= json_encode(round($avg, 1)) ?>;

      const style = getComputedStyle(document.documentElement);
      const accent = style.getPropertyValue('--accent').trim() || '#5b8cff';
      const muted = style.getPropertyValue('--muted').trim() || '#9aa4b2';
      const text = style.getPropertyValue('--text').trim() || '#e8ebef';
      const border = style.getPropertyValue('--border').trim() || '#2a313b';

      new Chart(document.getElementById('deviationChart'), {
        type: 'bar',
        data: {
          labels: labels,
          datasets: [{
            label: 'Deviation from average (lb)',
            data: deviations,
            backgroundColor: deviations.map(d => d >= 0 ? accent : muted),
            borderRadius: 3,
          }]
        },
        options: {
          responsive: true,
          plugins: {
            legend: { display: false },
            tooltip: {
              callbacks: {
                title: (items) => labels[items[0].dataIndex],
                label: (item) => {
                  const i = item.dataIndex;
                  const dev = deviations[i];
                  const sign = dev >= 0 ? '+' : '';
                  return [`${absoluteWeights[i]} lb`, `${sign}${dev} lb vs ${avg} lb avg`];
                }
              }
            }
          },
          scales: {
            x: { ticks: { color: muted }, grid: { color: border } },
            y: {
              ticks: { color: muted, callback: (v) => (v >= 0 ? '+' : '') + v },
              grid: { color: border },
              title: { display: true, text: 'lb vs. range average', color: muted }
            }
          }
        }
      });
    </script>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/partials_footer.php'; ?>
</body>
</html>
