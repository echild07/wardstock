<?php
// Works whether this file was uploaded flat (same dir as db.php -- the
// documented convention, hosted/README.md) or into a nested subfolder
// one level below the flattened app root -- checked both ways (27 Aug
// 2026) after the same class of assumption caused a real bug in
// standwhy's and beewell's own setup tools.
$__root = null;
foreach ([__DIR__, dirname(__DIR__)] as $__dir) {
    if (is_file($__dir . '/db.php')) { $__root = $__dir; break; }
}
if ($__root === null) {
    http_response_code(500);
    die('Could not find db.php next to this file or one level up -- check where it was uploaded.');
}
require_once $__root . '/db.php';
$__assetPrefix = ($__root === __DIR__) ? '' : '../'; // style.css lives next to db.php

// SAFETY: only allow this page to run if no user exists yet.
// Wrapped in try/catch (Aug 2026 — Ward hit a blank 500 on a fresh
// aileeward.com install with no way to tell why) so a bad DB
// host/name/user/password, or tables that don't exist yet because
// sql/ was never run, shows an actual reason instead of a dead page.
// This file only ever runs pre-account-creation and gets deleted right
// after use per README, so showing the raw exception here (unlike on
// the public login.php) is acceptable.
$dbError = null;
$count = 0;
try {
    $pdo = get_db();
    $count = $pdo->query('SELECT COUNT(*) AS c FROM wardstock_app_user')->fetch()['c'];
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

$message = '';
if ($dbError !== null) {
    $message = 'Database error: ' . $dbError . ' — check config/config.php (host/name/user/password), and confirm the SQL in sql/ has been run to create the tables.';
} elseif ($count > 0) {
    $message = 'A user already exists. For security, delete this file (setup.php) from your server now.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if ($username === '' || strlen($password) < 8) {
        $message = 'Username required and password must be at least 8 characters.';
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('INSERT INTO wardstock_app_user (username, password_hash) VALUES (?, ?)');
        $stmt->execute([$username, $hash]);
        $message = 'User created. Now DELETE this file (setup.php) from your server, then go to login.php.';
    }
}
?>
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Setup</title>
<link rel="stylesheet" href="<?= htmlspecialchars($__assetPrefix . 'style.css') ?>"></head>
<body>
<div class="wrap narrow">
  <h1>First-time setup</h1>
  <?php if ($message): ?><p class="notice"><?= htmlspecialchars($message) ?></p><?php endif; ?>
  <?php if ($dbError === null && $count == 0): ?>
  <form method="post">
    <label>Username <input type="text" name="username" required></label>
    <label>Password (8+ chars) <input type="password" name="password" required></label>
    <button type="submit">Create account</button>
  </form>
  <?php endif; ?>
</div>
</body>
</html>
