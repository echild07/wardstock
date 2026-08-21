<?php
// Landing page for the "Where When" nav section (Fulgrim, PLAN.md §18) —
// consolidates Analysis (new), Status, Export, and Therapy under one
// top-level tab, named after the HA-side analytic/processing engine
// itself ("wherewhen" — lowercase, no space; this page's own display
// text is styled title-case, a separate thing, see PLAN.md §18's naming
// note).
//
// Also home for non-direct-functional content (Ward's framing, Aug
// 2026) — not just the four sub-tabs. The LeeWard/marketing-flyer card
// moved here (from about.php) and the About/version footer links moved
// here (from export.php), on the reasoning that now this page exists,
// it's the natural place for anything that isn't core incident/daily-
// log/medication tracking. about.php itself still exists separately and
// unlinked-from-here on purpose — it's reachable pre-login (login.php's
// footer), which this require_login()'d page can't be.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_login();

$pdo = get_db();
$active = 'wherewhen';

$analysisCount = (int)$pdo->query('SELECT COUNT(DISTINCT analysis_key) c FROM analysis_results')->fetch()['c'];
$therapyCount = (int)$pdo->query('SELECT COUNT(*) c FROM therapy_sessions')->fetch()['c'];
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>WardStock — wherewhen</title>
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
      <h1>wherewhen</h1>
    </div>
  </header>
  <?php include __DIR__ . '/partials_nav.php'; ?>

  <fieldset>
    <legend>LeeWard</legend>
    <div class="founders">
      <div class="founder-card">
        <img src="leeward-badge.png" alt="LeeWard" class="founder-photo">
        <h4>LeeWard</h4>
        <p class="hint">The company behind WardStock and the wherewhen engine. <em>Leeward, n. — the side sheltered from the wind; where a ship finds its steadiest water.</em></p>
        <p class="hint"><a href="marketing.html">See the LeeWard / WardStock flyer →</a></p>
      </div>
    </div>
  </fieldset>

  <p class="hint">Everything the <code>wherewhen</code> engine produces or supports — correlation analysis, system status, data export, and therapy — lives here.</p>

  <div class="hub-grid">
    <a class="hub-card" href="analysis.php">
      <div class="hub-card-top">
        <span class="hub-icon tag-medical">◎</span>
        <span class="hub-count"><?= $analysisCount ?></span>
      </div>
      <h2>Analysis</h2>
      <p>Sleep, HRV, medication, mood, and incident correlation charts — computed by wherewhen.</p>
    </a>
    <a class="hub-card" href="status.php">
      <div class="hub-card-top">
        <span class="hub-icon tag-incident">◍</span>
      </div>
      <h2>Status</h2>
      <p>Is HA / Node-RED / analytics healthy right now — the one place that's always reachable.</p>
    </a>
    <a class="hub-card" href="export.php">
      <div class="hub-card-top">
        <span class="hub-icon tag-daily">⇩</span>
      </div>
      <h2>Export</h2>
      <p>Download your data, or restore from a previous export.</p>
    </a>
    <a class="hub-card" href="therapy.php">
      <div class="hub-card-top">
        <span class="hub-icon tag-therapy">◈</span>
        <span class="hub-count"><?= $therapyCount ?></span>
      </div>
      <h2>Therapy</h2>
      <p>Session notes, insights, homework — itself a form of analysis, per Ward's own framing.</p>
    </a>
  </div>

  <p class="hint" style="margin-top:32px; text-align:center;"><a href="about.php">About WardStock</a> · <a href="debug.php">App / database version</a></p>
</div>
</body>
</html>
