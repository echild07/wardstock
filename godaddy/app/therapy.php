<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_login();

$pdo = get_db();
$sessions = $pdo->query('SELECT * FROM therapy_sessions ORDER BY session_date DESC')->fetchAll();
$active = 'therapy';
$typeLabel = ['individual' => 'Individual', 'couples' => 'Couples', 'other' => 'Other'];

function avg_field($rows, $key) {
    $vals = array_values(array_filter(array_map(fn($r) => $r[$key], $rows), fn($v) => $v !== null));
    if (!$vals) return null;
    return array_sum($vals) / count($vals);
}
function fmt1($v) { return $v === null ? '—' : number_format($v, 1); }

$lastSession = $sessions[0] ?? null;
$report = null;
if ($lastSession) {
    $since = $lastSession['session_date'];
    $sinceDate = date('Y-m-d', strtotime($since));

    $stmt = $pdo->prepare('SELECT * FROM incidents WHERE occurred_at > ? ORDER BY occurred_at DESC');
    $stmt->execute([$since]);
    $reportIncidents = $stmt->fetchAll();

    $stmt = $pdo->prepare('SELECT * FROM daily_logs WHERE log_date >= ? ORDER BY log_date');
    $stmt->execute([$sinceDate]);
    $reportDailyLogs = $stmt->fetchAll();

    $daysInPeriod = (int)((strtotime('today') - strtotime($sinceDate)) / 86400) + 1;

    $report = [
        'since' => $since,
        'sinceDate' => $sinceDate,
        'daysInPeriod' => $daysInPeriod,
        'loggedDays' => count($reportDailyLogs),
        'incidents' => $reportIncidents,
        'anxietyCount' => count(array_filter($reportIncidents, fn($i) => $i['category'] === 'anxiety')),
        'cardiacCount' => count(array_filter($reportIncidents, fn($i) => $i['category'] === 'cardiac')),
        'avgSleep' => avg_field($reportDailyLogs, 'sleep_duration_hrs'),
        'avgEfficiency' => avg_field($reportDailyLogs, 'sleep_efficiency'),
        'avgRHR' => avg_field($reportDailyLogs, 'resting_hr'),
        'avgHRV' => avg_field($reportDailyLogs, 'hrv'),
        'totalSteps' => array_sum(array_map(fn($r) => (int)($r['steps'] ?? 0), $reportDailyLogs)),
        'totalExerciseMin' => array_sum(array_map(fn($r) => (int)($r['exercise_minutes'] ?? 0), $reportDailyLogs)),
        'totalCaffeine' => array_sum(array_map(fn($r) => (float)($r['caffeine_servings'] ?? 0), $reportDailyLogs)),
        'totalAlcohol' => array_sum(array_map(fn($r) => (float)($r['alcohol_drinks'] ?? 0), $reportDailyLogs)),
        'avgSom' => avg_field($reportDailyLogs, 'state_of_mind'),
    ];
}
$somLabels = [1 => 'Unpleasant', 2 => 'Slightly Unpleasant', 3 => 'Neutral', 4 => 'Slightly Enjoyed', 5 => 'Enjoyed'];
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>WardStock — Therapy</title>
<link rel="manifest" href="manifest.json">
<link rel="icon" href="favicon-32.png">
<link rel="apple-touch-icon" href="apple-touch-icon.png">
<meta name="theme-color" content="#0f1216">
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="wrap">
  <header class="topbar">
    <div class="brand">
      <img src="icon-192.png" alt="" width="36" height="36" class="brand-mark">
      <h1>Therapy</h1>
    </div>
    <a class="btn" href="therapy_form.php">+ New</a>
  </header>
  <?php include __DIR__ . '/partials_nav.php'; ?>
  <p class="hint"><a href="therapy_schedules.php">Manage recurring schedule →</a></p>

  <?php if ($report): ?>
  <h3 class="section-label">Since your last session — <?= htmlspecialchars(date('M j, Y', strtotime($report['since']))) ?> (<?= htmlspecialchars($typeLabel[$lastSession['session_type']] ?? $lastSession['session_type']) ?>)</h3>
  <div class="report-box">
    <div class="report-stats">
      <div class="report-stat"><span class="report-num"><?= count($report['incidents']) ?></span><span class="report-label">Incidents (<?= $report['anxietyCount'] ?> anxiety, <?= $report['cardiacCount'] ?> cardiac)</span></div>
      <div class="report-stat"><span class="report-num"><?= $report['loggedDays'] ?>/<?= $report['daysInPeriod'] ?></span><span class="report-label">Days logged</span></div>
      <div class="report-stat"><span class="report-num"><?= fmt1($report['avgSom']) ?></span><span class="report-label">Avg state of mind (1–5)</span></div>
      <div class="report-stat"><span class="report-num"><?= $report['avgSleep'] !== null ? htmlspecialchars(fmt_hours_minutes($report['avgSleep'])) : '—' ?></span><span class="report-label">Avg sleep</span></div>
      <div class="report-stat"><span class="report-num"><?= fmt1($report['avgEfficiency']) ?>%</span><span class="report-label">Avg sleep efficiency</span></div>
      <div class="report-stat"><span class="report-num"><?= fmt1($report['avgRHR']) ?></span><span class="report-label">Avg resting HR</span></div>
      <div class="report-stat"><span class="report-num"><?= fmt1($report['avgHRV']) ?></span><span class="report-label">Avg HRV</span></div>
      <div class="report-stat"><span class="report-num"><?= number_format($report['totalSteps']) ?></span><span class="report-label">Total steps</span></div>
      <div class="report-stat"><span class="report-num"><?= $report['totalExerciseMin'] ?>m</span><span class="report-label">Total exercise minutes</span></div>
      <div class="report-stat"><span class="report-num"><?= fmt1($report['totalCaffeine']) ?></span><span class="report-label">Total caffeine servings</span></div>
      <div class="report-stat"><span class="report-num"><?= fmt1($report['totalAlcohol']) ?></span><span class="report-label">Total drinks</span></div>
    </div>

    <?php if ($report['incidents']): ?>
      <h4 class="report-subhead">Incidents since last session</h4>
      <table class="day-table">
        <thead><tr><th>Date</th><th>Category</th><th>Symptoms</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($report['incidents'] as $inc): ?>
            <tr>
              <td><?= htmlspecialchars(date('M j, g:i A', strtotime($inc['occurred_at']))) ?></td>
              <td><span class="tag <?= $inc['category'] === 'cardiac' ? 'tag-cardiac' : 'tag-incident' ?>"><?= $inc['category'] === 'cardiac' ? 'Cardiac' : 'Anxiety' ?></span></td>
              <td>
                <?php
                  $sym = [];
                  foreach (['chest_sensation'=>'Chest','arm_sensation'=>'Arm','shoulder_sensation'=>'Shoulder','headache_sensation'=>'Headache'] as $col => $lbl) {
                      if (($inc[$col] ?? 'none') !== 'none') $sym[] = "$lbl: {$inc[$col]}";
                  }
                  echo htmlspecialchars($sym ? implode(', ', $sym) : '—');
                ?>
              </td>
              <td><a class="btn-link" href="incident_form.php?id=<?= (int)$inc['id'] ?>">View</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php else: ?>
      <p class="hint">No incidents since your last session.</p>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <h3 class="section-label">All sessions</h3>
  <?php if (!$sessions): ?>
    <p class="empty">No therapy sessions logged yet.</p>
  <?php else: ?>
  <div class="cards">
    <?php foreach ($sessions as $s): ?>
      <a class="card" href="therapy_form.php?id=<?= (int)$s['id'] ?>">
        <div class="card-top">
          <span class="card-date"><?= htmlspecialchars(date('M j, Y g:i A', strtotime($s['session_date']))) ?></span>
          <span class="tag tag-therapy"><?= htmlspecialchars($typeLabel[$s['session_type']] ?? ucfirst($s['session_type'])) ?></span>
        </div>
        <?php if ($s['mood_before'] !== null || $s['mood_after'] !== null): ?>
        <div class="card-tags">
          <?php if ($s['mood_before'] !== null): ?><span class="tag">Mood before <?= (int)$s['mood_before'] ?>/10</span><?php endif; ?>
          <?php if ($s['mood_after'] !== null): ?><span class="tag">Mood after <?= (int)$s['mood_after'] ?>/10</span><?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if ($s['summary']): ?>
          <p class="card-trigger"><?= htmlspecialchars(mb_strimwidth($s['summary'], 0, 140, '…')) ?></p>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
</body>
</html>
