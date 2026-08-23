<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_login();

$pdo = get_db();
$schedules = $pdo->query('SELECT * FROM wardstock_therapy_schedules ORDER BY active DESC, session_type')->fetchAll();
$active = 'wherewhen'; // moved under Where When (Fulgrim, PLAN.md §18)
$subActive = 'therapy';
$typeLabel = ['individual' => 'Individual', 'couples' => 'Couples', 'other' => 'Other'];
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>WardStock — Therapy Schedule</title>
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
      <h1>Therapy Schedule</h1>
    </div>
    <a class="btn" href="therapy_schedule_form.php">+ New</a>
  </header>
  <?php include __DIR__ . '/partials_nav.php'; ?>
  <?php include __DIR__ . '/partials_wherewhen_nav.php'; ?>

  <p class="hint">Recurring plans — the dashboard uses these to show a reminder on days a session is due, until you log what happened in <a href="therapy.php">Therapy</a>.</p>

  <?php if (!$schedules): ?>
    <p class="empty">No recurring therapy schedule set up yet.</p>
  <?php else: ?>
  <div class="cards">
    <?php foreach ($schedules as $s): ?>
      <a class="card" href="therapy_schedule_form.php?id=<?= (int)$s['id'] ?>">
        <div class="card-top">
          <span class="card-date"><?= htmlspecialchars($typeLabel[$s['session_type']] ?? ucfirst($s['session_type'])) ?></span>
          <span class="tag <?= $s['active'] ? 'lvl-mild' : 'lvl-none' ?>"><?= $s['active'] ? 'Active' : 'Paused' ?></span>
        </div>
        <p class="card-trigger">Every <?= (int)$s['frequency_days'] ?> days, starting <?= htmlspecialchars(date('M j, Y', strtotime($s['start_date']))) ?></p>
      </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/partials_footer.php'; ?>
</body>
</html>
