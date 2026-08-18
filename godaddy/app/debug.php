<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/app_version.php';
require_login();

$pdo = get_db();
$active = 'export';

$dbVersion = get_setting($pdo, 'db_version');
$inSync = ($dbVersion === APP_VERSION_SCHEMA);
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>WardStock — Debug / Version</title>
<link rel="manifest" href="manifest.json">
<link rel="icon" href="favicon-32.png">
<link rel="apple-touch-icon" href="apple-touch-icon.png">
<meta name="theme-color" content="#0f1216">
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="wrap">
  <header class="topbar">
    <h1>Debug / Version</h1>
    <a class="btn-link" href="export.php">← Back to Export</a>
  </header>
  <?php include __DIR__ . '/partials_nav.php'; ?>

  <table class="day-table">
    <tbody>
      <tr><td>App version</td><td><code><?= htmlspecialchars(APP_VERSION) ?> "<?= htmlspecialchars(APP_VERSION_NAME) ?>"</code></td></tr>
      <tr><td>App schema revision</td><td><code><?= htmlspecialchars(APP_VERSION_SCHEMA) ?></code> <span class="hint">(the Major.SQL part — this is what's compared against the database, not the full version)</span></td></tr>
      <tr><td>Database schema revision</td><td><code><?= $dbVersion !== null ? htmlspecialchars($dbVersion) : 'not set' ?></code></td></tr>
      <tr>
        <td>Status</td>
        <td>
          <?php if ($dbVersion === null): ?>
            <span class="error">❌ No version recorded in the database yet — run <code>sql/alter_add_version.sql</code> (or the matching <code>set_version.sql</code> for your current release).</span>
          <?php elseif ($inSync): ?>
            <span class="notice notice-success">✅ App and database schema are in sync.</span>
          <?php else: ?>
            <span class="error">❌ Mismatch — app code expects schema <?= htmlspecialchars(APP_VERSION_SCHEMA) ?> but the database says <?= htmlspecialchars($dbVersion) ?>. Usually means a SQL migration was shipped but not run yet (check <code>sql/</code> for a <code>set_version.sql</code> or <code>alter_*.sql</code> you haven't run). The code revision number (last digit of the app version) is expected to differ freely — only Major.SQL needs to match.</span>
          <?php endif; ?>
        </td>
      </tr>
    </tbody>
  </table>

  <h3 class="section-label">Environment</h3>
  <table class="day-table">
    <tbody>
      <tr><td>PHP version</td><td><code><?= htmlspecialchars(PHP_VERSION) ?></code></td></tr>
      <tr><td>MySQL/MariaDB version</td><td><code><?= htmlspecialchars($pdo->getAttribute(PDO::ATTR_SERVER_VERSION)) ?></code></td></tr>
      <tr><td>Server time</td><td><code><?= date('Y-m-d H:i:s T') ?></code></td></tr>
      <tr><td>curl extension</td><td><code><?= function_exists('curl_init') ? 'available' : 'MISSING' ?></code></td></tr>
    </tbody>
  </table>
</div>
</body>
</html>
