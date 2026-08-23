<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_login();

$pdo = get_db();
$startDate = app_now($pdo)->modify('-60 days')->format('Y-m-d'); // was new DateTime('today') — server default, not Ward's actual today (Aug 2026 fix)
$stmt = $pdo->prepare('SELECT log_date, weight FROM daily_logs WHERE log_date >= ? AND weight IS NOT NULL ORDER BY log_date');
$stmt->execute([$startDate]);
$points = $stmt->fetchAll();

$active = 'daily';

function fmt_lbs($v) { return rtrim(rtrim(number_format((float)$v, 1), '0'), '.'); }

$svg = null;
$stats = null;
if (count($points) >= 2) {
    $weights = array_map(fn($p) => (float)$p['weight'], $points);
    $minW = min($weights);
    $maxW = max($weights);
    $pad = max(2.0, ($maxW - $minW) * 0.15);
    $minW -= $pad;
    $maxW += $pad;
    if ($maxW == $minW) { $maxW += 2; $minW -= 2; }

    $firstDate = strtotime($points[0]['log_date']);
    $lastDate = strtotime($points[count($points) - 1]['log_date']);
    $totalSpanDays = max(1, ($lastDate - $firstDate) / 86400);

    $chartW = 680; $chartH = 260;
    $padL = 55; $padR = 20; $padT = 20; $padB = 36;
    $plotW = $chartW - $padL - $padR;
    $plotH = $chartH - $padT - $padB;

    $coords = [];
    foreach ($points as $p) {
        $dayOffset = (strtotime($p['log_date']) - $firstDate) / 86400;
        $x = $padL + $plotW * ($dayOffset / $totalSpanDays);
        $y = $padT + $plotH * (1 - (((float)$p['weight']) - $minW) / ($maxW - $minW));
        $coords[] = [round($x, 1), round($y, 1), $p];
    }

    $polyPoints = implode(' ', array_map(fn($c) => $c[0] . ',' . $c[1], $coords));

    ob_start();
    ?>
    <svg viewBox="0 0 <?= $chartW ?> <?= $chartH ?>" class="trend-chart" xmlns="http://www.w3.org/2000/svg">
      <line x1="<?= $padL ?>" y1="<?= $padT ?>" x2="<?= $padL ?>" y2="<?= $chartH - $padB ?>" class="chart-axis" />
      <line x1="<?= $padL ?>" y1="<?= $chartH - $padB ?>" x2="<?= $chartW - $padR ?>" y2="<?= $chartH - $padB ?>" class="chart-axis" />

      <text x="<?= $padL - 8 ?>" y="<?= $padT + 4 ?>" class="chart-label" text-anchor="end"><?= fmt_lbs($maxW) ?></text>
      <text x="<?= $padL - 8 ?>" y="<?= $chartH - $padB + 4 ?>" class="chart-label" text-anchor="end"><?= fmt_lbs($minW) ?></text>

      <text x="<?= $padL ?>" y="<?= $chartH - $padB + 20 ?>" class="chart-label"><?= htmlspecialchars(date('M j', $firstDate)) ?></text>
      <text x="<?= $chartW - $padR ?>" y="<?= $chartH - $padB + 20 ?>" class="chart-label" text-anchor="end"><?= htmlspecialchars(date('M j', $lastDate)) ?></text>

      <polyline points="<?= $polyPoints ?>" class="chart-line" />
      <?php foreach ($coords as $c): ?>
        <circle cx="<?= $c[0] ?>" cy="<?= $c[1] ?>" r="3" class="chart-point">
          <title><?= htmlspecialchars(date('M j, Y', strtotime($c[2]['log_date']))) ?>: <?= fmt_lbs($c[2]['weight']) ?> lbs</title>
        </circle>
      <?php endforeach; ?>
    </svg>
    <?php
    $svg = ob_get_clean();

    $stats = [
        'first' => $points[0],
        'last' => $points[count($points) - 1],
        'change' => (float)$points[count($points) - 1]['weight'] - (float)$points[0]['weight'],
        'avg' => array_sum($weights) / count($weights),
        'count' => count($points),
    ];
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>WardStock — Weight Trend</title>
<link rel="manifest" href="manifest.json">
<link rel="icon" href="favicon-32.png">
<link rel="apple-touch-icon" href="apple-touch-icon.png">
<meta name="theme-color" content="#0f1216">
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="wrap">
  <header class="topbar">
    <h1>Weight Trend</h1>
    <a class="btn-link" href="index.php">← Back to dashboard</a>
  </header>
  <?php include __DIR__ . '/partials_nav.php'; ?>

  <p class="hint">Last 60 days, weight entries only (from the Daily Log's Weight section). <a href="weight_deviation.php">View deviation-from-average chart →</a></p>

  <?php if (!$svg): ?>
    <p class="empty">Not enough weight entries in the last 60 days to chart a trend yet (<?= count($points) ?> so far — need at least 2).</p>
  <?php else: ?>
    <div class="report-box">
      <?= $svg ?>
    </div>
    <div class="report-stats">
      <div class="report-stat"><span class="report-num"><?= fmt_lbs($stats['last']['weight']) ?></span><span class="report-label">Latest (<?= htmlspecialchars(date('M j', strtotime($stats['last']['log_date']))) ?>)</span></div>
      <div class="report-stat"><span class="report-num"><?= $stats['change'] >= 0 ? '+' : '' ?><?= fmt_lbs($stats['change']) ?></span><span class="report-label">Change over period</span></div>
      <div class="report-stat"><span class="report-num"><?= fmt_lbs($stats['avg']) ?></span><span class="report-label">Average</span></div>
      <div class="report-stat"><span class="report-num"><?= $stats['count'] ?></span><span class="report-label">Entries logged</span></div>
    </div>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/partials_footer.php'; ?>
</body>
</html>
