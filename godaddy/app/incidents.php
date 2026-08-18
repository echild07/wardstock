<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_login();

$pdo = get_db();
$incidents = $pdo->query('SELECT * FROM incidents ORDER BY occurred_at DESC')->fetchAll();

function level_class($v) {
    $map = ['none' => 'lvl-none', 'mild' => 'lvl-mild', 'moderate' => 'lvl-moderate', 'severe' => 'lvl-severe'];
    return $map[$v] ?? '';
}
$active = 'incidents';
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>WardStock — Incidents</title>
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
      <h1>Incidents</h1>
    </div>
    <div class="topbar-actions">
      <a class="btn" href="incident_form.php?category=anxiety">+ Anxiety</a>
      <a class="btn btn-cardiac" href="incident_form.php?category=cardiac">+ Cardiac</a>
    </div>
  </header>
  <?php include __DIR__ . '/partials_nav.php'; ?>

  <?php if (!$incidents): ?>
    <p class="empty">No incidents logged yet.</p>
  <?php else: ?>
  <div class="cards">
    <?php foreach ($incidents as $inc): ?>
      <a class="card" href="incident_form.php?id=<?= (int)$inc['id'] ?>">
        <div class="card-top">
          <span class="card-date"><?= htmlspecialchars(date('M j, Y g:i A', strtotime($inc['occurred_at']))) ?></span>
          <span class="tag <?= $inc['category'] === 'cardiac' ? 'tag-cardiac' : 'tag-incident' ?>"><?= $inc['category'] === 'cardiac' ? 'Cardiac' : 'Anxiety' ?></span>
        </div>
        <div class="card-tags">
          <?php if ($inc['category'] === 'cardiac'): ?>
            <?php if ($inc['nitroglycerin_taken']): ?><span class="tag lvl-moderate">Nitroglycerin taken</span><?php endif; ?>
          <?php else: ?>
            <?php if ($inc['anxiety_intensity'] !== null): ?><span class="badge">Intensity <?= (int)$inc['anxiety_intensity'] ?>/10</span><?php endif; ?>
          <?php endif; ?>
          <span class="tag <?= level_class($inc['chest_sensation']) ?>">Chest: <?= htmlspecialchars($inc['chest_sensation']) ?></span>
          <span class="tag <?= level_class($inc['arm_sensation']) ?>">Arm: <?= htmlspecialchars($inc['arm_sensation']) ?></span>
          <span class="tag <?= level_class($inc['shoulder_sensation']) ?>">Shoulder: <?= htmlspecialchars($inc['shoulder_sensation']) ?></span>
          <span class="tag <?= level_class($inc['headache_sensation'] ?? 'none') ?>">Headache: <?= htmlspecialchars($inc['headache_sensation'] ?? 'none') ?></span>
          <?php if ($inc['medical_evaluation'] === 'yes'): ?><span class="tag lvl-mild">Medically evaluated</span><?php endif; ?>
        </div>
        <?php if ($inc['trigger_context']): ?>
          <p class="card-trigger"><?= htmlspecialchars(mb_strimwidth($inc['trigger_context'], 0, 140, '…')) ?></p>
        <?php endif; ?>
      </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
</body>
</html>
