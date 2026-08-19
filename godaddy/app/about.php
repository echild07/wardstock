<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/app_version.php';
require_login();

$pdo = get_db();
$active = '';
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
    <a class="btn-link" href="index.php">← Back to dashboard</a>
  </header>
  <?php include __DIR__ . '/partials_nav.php'; ?>

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
    <legend>Built with</legend>
    <p class="hint">No framework — plain PHP, PDO/MySQL, vanilla CSS/JS. Built with Claude Code.</p>
  </fieldset>
</div>
</body>
</html>
