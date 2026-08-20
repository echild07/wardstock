<?php
// Blood pressure trend (Fulgrim, feature list §1.2) — mirrors
// weight_trend.php's chart approach, but plots every individual reading
// (not one point/day) since BP is entered as multiple timestamped
// readings per day, not a single daily value — collapsing to one
// point/day would erase exactly the morning-vs-evening distinction the
// multi-reading table exists to preserve.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_login();

$pdo = get_db();
$startDate = (new DateTime('today'))->modify('-60 days')->format('Y-m-d');
$stmt = $pdo->prepare('SELECT * FROM blood_pressure_readings WHERE reading_at >= ? ORDER BY reading_at');
$stmt->execute([$startDate . ' 00:00:00']);
$points = $stmt->fetchAll();

$active = 'daily';

$svg = null;
$stats = null;
if (count($points) >= 2) {
    $systolics = array_map(fn($p) => (float)$p['systolic'], $points);
    $diastolics = array_map(fn($p) => (float)$p['diastolic'], $points);
    $minV = min(array_merge($systolics, $diastolics));
    $maxV = max(array_merge($systolics, $diastolics));
    $pad = max(4.0, ($maxV - $minV) * 0.15);
    $minV -= $pad;
    $maxV += $pad;
    if ($maxV == $minV) { $maxV += 10; $minV -= 10; }

    $firstAt = strtotime($points[0]['reading_at']);
    $lastAt = strtotime($points[count($points) - 1]['reading_at']);
    $totalSpanSec = max(1, $lastAt - $firstAt);

    $chartW = 680; $chartH = 260;
    $padL = 45; $padR = 20; $padT = 20; $padB = 36;
    $plotW = $chartW - $padL - $padR;
    $plotH = $chartH - $padT - $padB;

    $sysCoords = []; $diaCoords = [];
    foreach ($points as $p) {
        $x = round($padL + $plotW * ((strtotime($p['reading_at']) - $firstAt) / $totalSpanSec), 1);
        $sysCoords[] = [$x, round($padT + $plotH * (1 - ((float)$p['systolic'] - $minV) / ($maxV - $minV)), 1), $p];
        $diaCoords[] = [$x, round($padT + $plotH * (1 - ((float)$p['diastolic'] - $minV) / ($maxV - $minV)), 1), $p];
    }
    $sysPoly = implode(' ', array_map(fn($c) => $c[0] . ',' . $c[1], $sysCoords));
    $diaPoly = implode(' ', array_map(fn($c) => $c[0] . ',' . $c[1], $diaCoords));

    ob_start();
    ?>
    <svg viewBox="0 0 <?= $chartW ?> <?= $chartH ?>" class="trend-chart" xmlns="http://www.w3.org/2000/svg">
      <line x1="<?= $padL ?>" y1="<?= $padT ?>" x2="<?= $padL ?>" y2="<?= $chartH - $padB ?>" class="chart-axis" />
      <line x1="<?= $padL ?>" y1="<?= $chartH - $padB ?>" x2="<?= $chartW - $padR ?>" y2="<?= $chartH - $padB ?>" class="chart-axis" />

      <text x="<?= $padL - 8 ?>" y="<?= $padT + 4 ?>" class="chart-label" text-anchor="end"><?= (int)round($maxV) ?></text>
      <text x="<?= $padL - 8 ?>" y="<?= $chartH - $padB + 4 ?>" class="chart-label" text-anchor="end"><?= (int)round($minV) ?></text>

      <text x="<?= $padL ?>" y="<?= $chartH - $padB + 20 ?>" class="chart-label"><?= htmlspecialchars(date('M j', $firstAt)) ?></text>
      <text x="<?= $chartW - $padR ?>" y="<?= $chartH - $padB + 20 ?>" class="chart-label" text-anchor="end"><?= htmlspecialchars(date('M j', $lastAt)) ?></text>

      <polyline points="<?= $sysPoly ?>" class="chart-line" />
      <?php foreach ($sysCoords as $c): ?>
        <circle cx="<?= $c[0] ?>" cy="<?= $c[1] ?>" r="3" class="chart-point">
          <title><?= htmlspecialchars(date('M j, Y g:i A', strtotime($c[2]['reading_at']))) ?>: <?= (int)$c[2]['systolic'] ?> systolic</title>
        </circle>
      <?php endforeach; ?>

      <polyline points="<?= $diaPoly ?>" class="chart-line-diastolic" />
      <?php foreach ($diaCoords as $c): ?>
        <circle cx="<?= $c[0] ?>" cy="<?= $c[1] ?>" r="3" class="chart-point-diastolic">
          <title><?= htmlspecialchars(date('M j, Y g:i A', strtotime($c[2]['reading_at']))) ?>: <?= (int)$c[2]['diastolic'] ?> diastolic</title>
        </circle>
      <?php endforeach; ?>
    </svg>
    <?php
    $svg = ob_get_clean();

    $latest = $points[count($points) - 1];
    $stats = [
        'latest' => $latest,
        'latest_cat' => bp_category($latest['systolic'], $latest['diastolic']),
        'avg_sys' => array_sum($systolics) / count($systolics),
        'avg_dia' => array_sum($diastolics) / count($diastolics),
        'count' => count($points),
    ];
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>WardStock — Blood Pressure Trend</title>
<link rel="manifest" href="manifest.json">
<link rel="icon" href="favicon-32.png">
<link rel="apple-touch-icon" href="apple-touch-icon.png">
<meta name="theme-color" content="#0f1216">
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="wrap">
  <header class="topbar">
    <h1>Blood Pressure Trend</h1>
    <a class="btn-link" href="index.php">← Back to dashboard</a>
  </header>
  <?php include __DIR__ . '/partials_nav.php'; ?>

  <p class="hint">Last 60 days, every reading logged (from the Daily Log's Blood Pressure section) — blue is systolic, amber is diastolic. Categories are the standard AHA thresholds, shown for reference only — not medical advice.</p>

  <?php if (!$svg): ?>
    <p class="empty">Not enough readings in the last 60 days to chart a trend yet (<?= count($points) ?> so far — need at least 2).</p>
  <?php else: ?>
    <div class="report-box">
      <?= $svg ?>
    </div>
    <div class="report-stats">
      <div class="report-stat"><span class="report-num"><?= (int)$stats['latest']['systolic'] ?>/<?= (int)$stats['latest']['diastolic'] ?></span><span class="report-label">Latest (<?= htmlspecialchars(date('M j, g:i A', strtotime($stats['latest']['reading_at']))) ?>)</span></div>
      <div class="report-stat"><span class="report-num"><?= htmlspecialchars(bp_category_label($stats['latest_cat'])) ?></span><span class="report-label">Latest category</span></div>
      <div class="report-stat"><span class="report-num"><?= (int)round($stats['avg_sys']) ?>/<?= (int)round($stats['avg_dia']) ?></span><span class="report-label">Average</span></div>
      <div class="report-stat"><span class="report-num"><?= $stats['count'] ?></span><span class="report-label">Readings logged</span></div>
    </div>
  <?php endif; ?>
</div>
</body>
</html>
