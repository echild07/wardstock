<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_login();

$pdo = get_db();
$meds = $pdo->query('SELECT * FROM wardstock_medications ORDER BY name, start_date DESC')->fetchAll();
$today = app_today($pdo); // was date('Y-m-d') — server default, not Ward's actual today (Aug 2026 fix)
$active = 'medications';

function med_status($m, $today) {
    if ($m['end_date'] !== null && $m['end_date'] < $today) return 'ended';
    if ($m['start_date'] > $today) return 'upcoming';
    return 'active';
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>WardStock — Medications</title>
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
      <h1>Medications</h1>
    </div>
    <a class="btn" href="medication_form.php">+ New</a>
  </header>
  <?php include __DIR__ . '/partials_nav.php'; ?>

  <p class="hint">A dosage change is logged as a new row (with its own start date) rather than editing the old one, so history is preserved — end the old dose, then add the new one.</p>

  <?php if (!$meds): ?>
    <p class="empty">No medications on file yet.</p>
  <?php else: ?>
  <div class="cards">
    <?php foreach ($meds as $m): $status = med_status($m, $today); ?>
      <a class="card" href="medication_form.php?id=<?= (int)$m['id'] ?>">
        <div class="card-top">
          <span class="card-date"><?= htmlspecialchars($m['name']) ?><?= $m['dosage'] ? ' — ' . htmlspecialchars($m['dosage']) : '' ?></span>
          <span class="tag <?= $status === 'active' ? 'lvl-mild' : ($status === 'upcoming' ? 'lvl-moderate' : 'lvl-none') ?>">
            <?= $status === 'active' ? 'Active' : ($status === 'upcoming' ? 'Upcoming' : 'Ended') ?>
          </span>
        </div>
        <div class="card-tags">
          <span class="tag <?= $m['med_type'] === 'as_needed' ? 'tag-therapy' : 'tag-daily' ?>"><?= $m['med_type'] === 'as_needed' ? 'As needed' : 'Scheduled' ?></span>
          <span class="tag"><?= htmlspecialchars($m['cadence']) ?></span>
        </div>
        <p class="card-trigger">
          Started <?= htmlspecialchars(date('M j, Y', strtotime($m['start_date']))) ?><?php if ($m['end_date']): ?> · Ended <?= htmlspecialchars(date('M j, Y', strtotime($m['end_date']))) ?><?php endif; ?>
        </p>
      </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/partials_footer.php'; ?>
</body>
</html>
