<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_login();

$pdo = get_db();
$logs = $pdo->query('SELECT * FROM daily_logs ORDER BY log_date DESC')->fetchAll();
$active = 'daily';
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>WardStock — Daily Log</title>
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
      <h1>Daily Log</h1>
    </div>
    <a class="btn" href="daily_form.php">+ New</a>
  </header>
  <?php include __DIR__ . '/partials_nav.php'; ?>
  <p class="hint"><a href="oura_sync.php">⬇ Pull from Oura</a></p>

  <?php if (!$logs): ?>
    <p class="empty">No daily logs yet.</p>
  <?php else: ?>
  <div class="cards">
    <?php foreach ($logs as $log): ?>
      <a class="card" href="daily_form.php?id=<?= (int)$log['id'] ?>">
        <div class="card-top">
          <span class="card-date"><?= htmlspecialchars(date('D, M j, Y', strtotime($log['log_date']))) ?></span>
          <?php if ($log['sleep_efficiency'] !== null): ?>
            <span class="badge">Efficiency <?= (int)$log['sleep_efficiency'] ?>%</span>
          <?php endif; ?>
        </div>
        <div class="card-tags">
          <?php if ($log['sleep_duration_hrs'] !== null): ?><span class="tag">Sleep <?= htmlspecialchars(fmt_hours_minutes($log['sleep_duration_hrs'])) ?></span><?php endif; ?>
          <?php if ($log['resting_hr'] !== null): ?><span class="tag">RHR <?= (int)$log['resting_hr'] ?></span><?php endif; ?>
          <?php if ($log['hrv'] !== null): ?><span class="tag">HRV <?= (int)$log['hrv'] ?></span><?php endif; ?>
          <?php if ($log['mood_rating'] !== null): ?><span class="tag">Mood <?= (int)$log['mood_rating'] ?>/10</span><?php endif; ?>
          <?php if ($log['steps'] !== null): ?><span class="tag">Steps <?= number_format((int)$log['steps']) ?></span><?php endif; ?>
        </div>
        <?php if ($log['caffeine'] || $log['alcohol']): ?>
          <p class="card-trigger"><?= htmlspecialchars(mb_strimwidth(trim(($log['caffeine'] ? 'Caffeine: '.$log['caffeine'].' ' : '').($log['alcohol'] ? 'Alcohol: '.$log['alcohol'] : '')), 0, 140, '…')) ?></p>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
</body>
</html>
