<?php
// EKG (Kardia) recordings — list page. GoDaddy-side slice of
// homeassistant/EKG_DESIGN.md; see ecg_form.php's header comment for the
// full scoping note (manual entry only, no PDF parser yet).
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_login();

$pdo = get_db();
// Guarded — this page is reachable from nav/the dashboard hub card the
// moment this code deploys, which can be before the SQL migration that
// creates ecg_recordings has actually been run (sql/upgrade_from_3.0.0.sql).
// A friendly message here beats a raw 500 on a table that doesn't exist yet.
$schemaMissing = false;
try {
    $recordings = $pdo->query('SELECT * FROM ecg_recordings ORDER BY recorded_at DESC')->fetchAll();
} catch (Throwable $e) {
    $schemaMissing = true;
    $recordings = [];
}

// Same three-bucket idea as incidents.php's level_class(), applied to
// Kardia's own determination codes instead of a severity word — reuses
// the existing lvl-mild/lvl-moderate/lvl-severe tag colors rather than
// inventing a fourth color scheme just for this page.
function determination_class($code) {
    $concerning = ['possible_atrial_fibrillation'];
    $attention = ['bradycardia', 'tachycardia', 'sinus_rhythm_with_pvcs', 'sinus_rhythm_with_sve', 'sinus_rhythm_with_wide_qrs'];
    if ($code === 'normal_sinus_rhythm') return 'lvl-mild';
    if (in_array($code, $concerning, true)) return 'lvl-severe';
    if (in_array($code, $attention, true)) return 'lvl-moderate';
    return 'lvl-none'; // unclassified / unreadable / other / not yet set
}
$active = 'ecg';
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>WardStock — EKG</title>
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
      <h1>EKG</h1>
    </div>
    <div class="topbar-actions">
      <a class="btn" href="ecg_form.php">+ New recording</a>
    </div>
  </header>
  <?php include __DIR__ . '/partials_nav.php'; ?>

  <p class="hint">This stores and organizes personal EKG recordings — it does not diagnose medical conditions, rule out a heart attack, or replace professional medical evaluation. Kardia's automated determination is preserved as vendor-supplied information and may require clinician review.</p>

  <?php if ($schemaMissing): ?>
    <p class="error">❌ The EKG tables don't exist in this database yet — run <code>sql/upgrade_from_3.0.0.sql</code> (safe to re-run) in phpMyAdmin, then reload this page.</p>
  <?php elseif (!$recordings): ?>
    <p class="empty">No EKG recordings logged yet.</p>
  <?php else: ?>
  <div class="cards">
    <?php foreach ($recordings as $r): ?>
      <a class="card" href="ecg_form.php?id=<?= (int)$r['id'] ?>">
        <div class="card-top">
          <span class="card-date"><?= htmlspecialchars(date('M j, Y g:i A', strtotime($r['recorded_at']))) ?></span>
          <span class="tag tag-ecg"><?= htmlspecialchars($r['device_product']) ?></span>
        </div>
        <div class="card-tags">
          <span class="tag <?= determination_class($r['determination_code']) ?>"><?= htmlspecialchars($r['determination_text'] ?: ($r['determination_code'] ?: 'Not yet set')) ?></span>
          <?php if ($r['average_heart_rate_bpm'] !== null): ?><span class="badge"><?= (int)$r['average_heart_rate_bpm'] ?> bpm</span><?php endif; ?>
          <?php if ($r['symptoms_present']): ?><span class="tag lvl-moderate">Symptoms noted</span><?php endif; ?>
          <?php if ($r['clinician_reviewed']): ?><span class="tag lvl-mild">Clinician reviewed</span><?php endif; ?>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
</body>
</html>
