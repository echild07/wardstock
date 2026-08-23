<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/app_version.php';
start_session();

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $pdo = get_db();
    $stmt = $pdo->prepare('SELECT * FROM app_user WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if ($user && password_verify($password, $user['password_hash'])) {
        session_regenerate_id(true);
        unset($_SESSION['demo_mode']); // in case this browser had a demo session going (demo/index.php)
        $_SESSION['user_id'] = $user['id'];
        header('Location: index.php');
        exit;
    }
    $error = 'Invalid username or password.';
}

// System health check (Ward, Aug 2026 — "validate the SQL server is
// connected, its version matches, and the system would run", surfaced
// right on this public login page since this is exactly where a fresh
// GoDaddy install was failing: setup.php gave a blank 500 with no
// explanation when the database wasn't reachable yet, rather than saying
// why). Deliberately independent of the login form above and of its own
// $pdo — a broken DB must never take the login page down, and this has
// to render something useful precisely when the DB is NOT working. Each
// check is its own try/catch so "can't connect" vs "connected but no
// schema yet" vs "schema ok but no account yet" (three different points
// in a fresh install) are told apart instead of collapsing into one
// generic failure.
$health = [
    'dbConnected' => false,
    'mysqlVersion' => null,
    'schemaChecked' => false,
    'dbVersion' => null,
    'schemaOk' => false,
    'hasAccount' => null, // null = couldn't even check
];
try {
    $healthPdo = get_db();
    $health['dbConnected'] = true;
    $health['mysqlVersion'] = $healthPdo->getAttribute(PDO::ATTR_SERVER_VERSION);
} catch (Throwable $e) {
    // Logged server-side only — this page is public/unauthenticated, so
    // never echo the raw exception text here (it can contain the DB host
    // or username).
    error_log('WardStock login health-check: DB connection failed: ' . $e->getMessage());
}
if ($health['dbConnected']) {
    try {
        $health['dbVersion'] = get_setting($healthPdo, 'db_version');
        $health['schemaChecked'] = true;
        $health['schemaOk'] = ($health['dbVersion'] === APP_VERSION_SCHEMA);
    } catch (Throwable $e) {
        error_log('WardStock login health-check: schema check failed: ' . $e->getMessage());
    }
    try {
        $health['hasAccount'] = (int)$healthPdo->query('SELECT COUNT(*) c FROM app_user')->fetch()['c'] > 0;
    } catch (Throwable $e) {
        error_log('WardStock login health-check: app_user check failed: ' . $e->getMessage());
    }
}
$systemReady = $health['dbConnected'] && $health['schemaOk'] && $health['hasAccount'] === true;
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Log in — WardStock</title>
<link rel="manifest" href="manifest.json">
<link rel="icon" href="favicon-32.png">
<link rel="apple-touch-icon" href="apple-touch-icon.png">
<meta name="theme-color" content="#0f1216">
<link rel="stylesheet" href="style.css">
</head>
<body class="login-body">
<div class="login-bg" style="background-image: url('login-bg.png');"></div>
<div class="wrap landing">
  <img class="logo" src="icon-512.png" alt="WardStock" width="140" height="140">
  <h1>WardStock</h1>
  <p class="tagline">Taking stock of Ward</p>

  <div class="landing-grid">
    <div class="landing-card landing-about">
      <h2>About</h2>
      <?php // Copy matches about.php's "What this is" fieldset verbatim — that
            // page is the source of truth for this wording, this is just a
            // shorter teaser pointing at it, not an independent claim. ?>
      <p>WardStock — "taking stock of Ward." A private, single-user health log for anxiety and cardiac incidents, daily health tracking, medications, and therapy — built to be exact when it matters and simple enough to reach for in the moment.</p>
      <p><a href="about.php">Learn more →</a></p>
    </div>

    <div class="landing-card landing-login">
      <?php if ($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
      <form method="post">
        <label>Username <input type="text" name="username" required autofocus></label>
        <label>Password <input type="password" name="password" required></label>
        <button type="submit">Log in</button>
      </form>
      <?php if (defined('DEMO_DB_NAME') && DEMO_DB_NAME !== ''): ?>
        <a class="btn-secondary landing-demo-btn" href="demo/">🎭 Try the Demo</a>
        <p class="hint landing-demo-hint" style="margin-top:16px;"><a href="demo/">🎭 View interactive demo</a> — click through the app with sample data, no login needed</p>
      <?php endif; ?>
    </div>

    <div class="landing-card landing-tracking">
      <h2>What's tracked</h2>
      <?php // Copy matches about.php's "What's tracked" fieldset verbatim — see note above. ?>
      <p>Incidents (anxiety &amp; cardiac), Daily Log (sleep, exercise, caffeine, alcohol, medication, mood, weight), Medications (full dosage history), Therapy (sessions, recurring schedule, since-last-session report), and an always-available JSON Export/Import for your own data.</p>
      <p><a href="about.php">Learn more →</a></p>
    </div>
  </div>

  <p class="hint" style="margin-top:24px;"><a href="../">LeeWard home</a> · <a href="privacy.php">Privacy Policy</a> · <a href="terms.php">Terms of Service</a> · <a href="about.php">About</a> · <a href="marketing.html">LeeWard / WardStock flyer</a></p>
  <?php
    // Version on the login page itself (Ward, Aug 2026 — "so you can
    // validate which version is currently running... prefer you show it
    // so I can see it too"). Visible, not hidden in HTML — the whole
    // point was for both of us to be able to check this without digging,
    // and about.php already shows it once logged in, so there's nothing
    // sensitive about surfacing it a page earlier too.
  ?>
  <p class="hint" style="margin-top:8px; opacity:0.6;">v<?= htmlspecialchars(APP_VERSION) ?> "<?= htmlspecialchars(APP_VERSION_NAME) ?>"</p>

  <div class="hint" style="margin-top:20px; text-align:left; border-top:1px solid var(--border); padding-top:12px;">
    <p class="section-label" style="margin:0 0 6px;">System status</p>
    <?php if (!$health['dbConnected']): ?>
      <p class="error">❌ Database unreachable — check the DB host/name/user/password in <code>config/config.php</code> on this server (a placeholder value left in <code>DB_PASS</code> is a common cause on a fresh install). Server error log has the exact reason.</p>
    <?php else: ?>
      <p class="notice notice-success">✅ Database connected<?= $health['mysqlVersion'] ? ' (MySQL/MariaDB ' . htmlspecialchars($health['mysqlVersion']) . ')' : '' ?></p>
      <?php if (!$health['schemaChecked'] || $health['dbVersion'] === null): ?>
        <p class="error">❌ Schema not found or incomplete — run the SQL in <code>sql/</code> (see <code>README.md</code>) to create the tables.</p>
      <?php elseif ($health['schemaOk']): ?>
        <p class="notice notice-success">✅ Schema up to date (<?= htmlspecialchars($health['dbVersion']) ?>)</p>
      <?php else: ?>
        <p class="error">❌ Schema mismatch — app expects <?= htmlspecialchars(APP_VERSION_SCHEMA) ?>, database has <?= htmlspecialchars($health['dbVersion']) ?>. Run the matching <code>sql/upgrade_from_*.sql</code>.</p>
      <?php endif; ?>
      <?php if ($health['hasAccount'] === true): ?>
        <p class="notice notice-success">✅ Account configured</p>
      <?php elseif ($health['hasAccount'] === false): ?>
        <p class="notice-warning">⚠️ No account yet — upload <code>setup-delete-after-use/setup.php</code>, visit it to create your login, then delete it from the server.</p>
      <?php endif; ?>
    <?php endif; ?>
    <p style="margin-top:8px;"><?= $systemReady ? '<span class="notice notice-success">✅ System ready.</span>' : '<span class="error">⚠️ Not fully set up yet — see above.</span>' ?></p>
  </div>
</div>
<?php include __DIR__ . '/partials_footer.php'; ?>
</body>
</html>
