<?php
// Intentionally NOT behind require_login() — linked from login.php's
// footer (alongside Privacy/Terms), so it needs to work before signing
// in. Nothing sensitive here: app description, version numbers, and
// public-facing company/founder info.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/app_version.php';

$pdo = get_db();
$isLoggedIn = is_logged_in();
$dbVersion = get_setting($pdo, 'db_version');
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>WardStock — About</title>
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
      <h1>About WardStock</h1>
    </div>
    <a class="btn-link" href="<?= $isLoggedIn ? 'index.php' : 'login.php' ?>">← Back to <?= $isLoggedIn ? 'dashboard' : 'login' ?></a>
  </header>
  <?php if ($isLoggedIn): ?>
    <?php include __DIR__ . '/partials_nav.php'; ?>
  <?php endif; ?>

  <fieldset>
    <legend>What this is</legend>
    <p>WardStock — "taking stock of Ward." A private, single-user health log for anxiety and cardiac incidents, daily health tracking, medications, and therapy — built to be exact when it matters and simple enough to reach for in the moment.</p>
    <p>Part of the <strong>Lucius</strong> project — this app is the always-reachable piece you actually open. A separate, home-based piece (Node-RED + InfluxDB, running on Home Assistant) handles background sync with your Oura Ring, deeper analysis, and disaster-recovery backup, entirely on your own hardware — nothing raw ever leaves your home network. See <code>README.md</code> at the project root if you have the source, or ask whoever maintains this install.</p>
  </fieldset>

  <fieldset>
    <legend>Version</legend>
    <table class="day-table">
      <tbody>
        <tr><td>App</td><td><code><?= htmlspecialchars(APP_VERSION) ?></code> "<?= htmlspecialchars(APP_VERSION_NAME) ?>"</td></tr>
        <tr><td>Database schema</td><td><code><?= $dbVersion !== null ? htmlspecialchars($dbVersion) : 'not set' ?></code></td></tr>
      </tbody>
    </table>
    <p class="hint">Full version/sync detail on <a href="debug.php">Debug / Version</a>. HA/Node-RED sync status on <a href="status.php">System Status</a>.</p>
  </fieldset>

  <fieldset>
    <legend>What's tracked</legend>
    <p>Incidents (anxiety &amp; cardiac), Daily Log (sleep, exercise, caffeine, alcohol, medication, mood, weight), Medications (full dosage history), Therapy (sessions, recurring schedule, since-last-session report), and an always-available JSON Export/Import for your own data.</p>
  </fieldset>

  <fieldset>
    <legend>Personal use only</legend>
    <p>One vessel, one crew, one log — this is a single-user application with one shared login, built for and used by one person. See <a href="privacy.php">Privacy Policy</a> and <a href="terms.php">Terms</a> for the specifics.</p>
  </fieldset>

  <fieldset>
    <legend>LeeWard</legend>
    <div class="founders">
      <div class="founder-card">
        <img src="leeward-badge.png" alt="LeeWard" class="founder-photo">
        <h4>LeeWard</h4>
        <p class="hint">The company behind WardStock and the Lucius project. <em>Leeward, n. — the side sheltered from the wind; where a ship finds its steadiest water.</em></p>
        <p class="hint"><a href="marketing.html">See the LeeWard / WardStock flyer →</a></p>
      </div>
    </div>
  </fieldset>

  <fieldset>
    <legend>Founders</legend>
    <div class="founders">
      <div class="founder-card">
        <img src="leeward-badge.png" alt="Ward Bowman" class="founder-photo">
        <h4>Ward Bowman</h4>
      </div>
      <div class="founder-card">
        <img src="leeward-badge.png" alt="Lisa Bowman" class="founder-photo">
        <h4>Lisa Bowman</h4>
      </div>
    </div>

    <div class="founder-bio">
      <h5>Ward Bowman</h5>
      <p>Ward Bowman has spent over 30 years building the systems that let people see clearly into complicated things — SCADA systems, Historians, MES platforms, and some of the earliest IIoT solutions, deployed across industries from Consumer Packaged Goods to Life Sciences to Oil and Gas. He currently leads go-to-market strategy for software solutions at <strong>Rockwell Automation</strong>, following five and a half years as Senior Director of Product Management at <strong>PTC</strong> and seventeen years at <strong>GE</strong>, where he built and led a wireless monitoring and hosting business years ahead of "IIoT" becoming an industry term, architected Big Data and mobility platforms, and was named <strong>GE Fanuc Engineer of the Year in 2009</strong>. Colleagues across different eras and working relationships have consistently described him the same way: someone who clears distractions and bureaucracy out of his team's way, hands out real ownership rather than micromanaging, and holds the commercial and technical sides of a problem at once — seeing not just the answer for now, but what the next few problems down the road will require.</p>
      <p>He served <strong>15 years in the U.S. Army and Massachusetts Army National Guard</strong>, in Air Defense Artillery and Combat Engineering, and holds a <strong>B.S. in Computer Engineering from the University of Massachusetts Dartmouth</strong>. WardStock — and the wider LeeWard project it lives under — grew out of the same throughline as the rest of his work: build the thing that tells you the truth about what's actually happening, keep it simple enough to trust in the moment it matters, and never mistake a dashboard for the real work underneath it.</p>
    </div>

    <div class="founder-bio">
      <h5>Lisa Bowman</h5>
      <p class="hint">Bio coming soon.</p>
    </div>
  </fieldset>

  <fieldset>
    <legend>Built with</legend>
    <p class="hint">No framework — plain PHP, PDO/MySQL, vanilla CSS/JS. Built with Claude Code.</p>
  </fieldset>
</div>
</body>
</html>
