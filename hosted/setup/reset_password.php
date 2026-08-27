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

$pdo = get_db();
$users = $pdo->query('SELECT id, username FROM wardstock_app_user')->fetchAll();

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = (int)($_POST['user_id'] ?? 0);
    $password = $_POST['password'] ?? '';
    if (strlen($password) < 8) {
        $message = 'Password must be at least 8 characters.';
    } else {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('UPDATE wardstock_app_user SET password_hash = ? WHERE id = ?');
        $stmt->execute([$hash, $userId]);
        $message = 'Password updated. Now DELETE this file (reset_password.php) from your server, then log in with the new password.';
        $users = $pdo->query('SELECT id, username FROM wardstock_app_user')->fetchAll(); // refresh
    }
}
?>
<!doctype html>
<html>
<head><meta charset="utf-8"><title>Reset password</title>
<link rel="stylesheet" href="<?= htmlspecialchars($__assetPrefix . 'style.css') ?>"></head>
<body>
<div class="wrap narrow">
  <h1>Reset password</h1>
  <?php if ($message): ?><p class="notice"><?= htmlspecialchars($message) ?></p><?php endif; ?>
  <?php if (!$users): ?>
    <p>No user account exists yet — use setup.php instead.</p>
  <?php else: ?>
  <form method="post">
    <label>Account
      <select name="user_id">
        <?php foreach ($users as $u): ?>
          <option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars($u['username']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>New password (8+ chars) <input type="password" name="password" required></label>
    <button type="submit">Update password</button>
  </form>
  <?php endif; ?>
</div>
</body>
</html>
