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
    <a class="btn-link" href="../">LeeWard home</a>
    <a class="btn-link" href="<?= $isLoggedIn ? 'index.php' : 'login.php' ?>">← Back to <?= $isLoggedIn ? 'dashboard' : 'login' ?></a>
  </header>
  <?php if ($isLoggedIn): ?>
    <?php include __DIR__ . '/partials_nav.php'; ?>
  <?php endif; ?>

  <fieldset>
    <legend>What this is</legend>
    <p>WardStock — "taking stock of Ward." A private, single-user health log for anxiety and cardiac incidents, daily health tracking, medications, and therapy — built to be exact when it matters and simple enough to reach for in the moment.</p>
    <p>Part of <strong>LeeWard</strong>. This app is the always-reachable piece you actually open. A separate, home-based engine (also nicknamed wherewhen) on Home Assistant handles Oura sync, analysis, and backup — nothing raw leaves the home network. LeeWard’s manufacturing product, a different codebase, lives at <a href="../wherewhen/login.php">/wherewhen/</a>.</p>
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
    <legend>Founders</legend>
    <div class="founders">
      <div class="founder-card">
        <img src="leeward-badge.png" alt="Ward Bowman — click to read bio" class="founder-photo" data-founder="ward" tabindex="0" role="button" aria-controls="founder-bio-display">
        <h4>Ward Bowman</h4>
      </div>
      <div class="founder-card">
        <img src="leeward-badge.png" alt="Lisa Bowman — click to read bio" class="founder-photo" data-founder="lisa" tabindex="0" role="button" aria-controls="founder-bio-display">
        <h4>Lisa Bowman</h4>
      </div>
    </div>

    <div id="founder-bio-display" class="founder-bio">
      <p class="hint">Click a founder's photo to read their bio.</p>
    </div>

    <template id="bio-ward">
      <h5>Ward Bowman</h5>
      <p>Ward Bowman has spent 30-plus years building SCADA systems, Historians, MES platforms, and some of the earliest IIoT solutions in the industry, across consumer goods, life sciences, and oil and gas. He's currently at <strong>Rockwell Automation</strong>, running go-to-market strategy for software solutions. Before that: five and a half years as Senior Director of Product Management at <strong>PTC</strong>, and seventeen years at <strong>GE</strong>, where he built a wireless monitoring business years before "IIoT" was a word anyone used, and was later named <strong>GE Fanuc Engineer of the Year in 2009</strong>. People who've worked with him tend to say the same two things: he clears bureaucracy out of his team's way, and he's usually already solving tomorrow's problem while everyone else is on today's.</p>
      <p>He served <strong>15 years in the U.S. Army and Massachusetts Army National Guard</strong>, in Air Defense Artillery and Combat Engineering, and holds a <strong>B.S. in Computer Engineering from UMass Dartmouth</strong>. WardStock is the same idea on a much smaller scale — built to tell the truth about what's happening, and stay out of the way otherwise.</p>
    </template>

    <template id="bio-lisa">
      <h5>Lisa Bowman</h5>
      <p class="hint">Bio coming soon.</p>
    </template>
  </fieldset>

  <script>
  (function () {
    var display = document.getElementById('founder-bio-display');
    document.querySelectorAll('.founder-photo[data-founder]').forEach(function (img) {
      function show() {
        var tpl = document.getElementById('bio-' + img.getAttribute('data-founder'));
        if (tpl) display.innerHTML = tpl.innerHTML;
      }
      img.addEventListener('click', show);
      img.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); show(); }
      });
    });
  })();
  </script>

  <fieldset>
    <legend>Built with</legend>
    <p class="hint">No framework — plain PHP, PDO/MySQL, vanilla CSS/JS. Built with Claude Code.</p>
    <p class="hint">Claude Sonnet 5 (Anthropic) did the actual writing here — schema, PHP, the Node-RED flows, and the debugging that came with all three. Whatever's solid about this app came out of that; whatever's still rough is probably just a corner we hadn't gotten to yet.</p>
  </fieldset>
</div>
</body>
</html>
