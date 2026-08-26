<?php
require_once __DIR__ . '/db.php';

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
<link rel="stylesheet" href="style.css"></head>
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
