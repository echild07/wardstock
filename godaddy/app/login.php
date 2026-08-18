<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
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
        $_SESSION['user_id'] = $user['id'];
        header('Location: index.php');
        exit;
    }
    $error = 'Invalid username or password.';
}
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
<div class="login-bg" style="background-image: url('icon-512.png');"></div>
<div class="wrap narrow">
  <img class="logo" src="icon-512.png" alt="WardStock" width="140" height="140">
  <h1>WardStock</h1>
  <p class="tagline">Taking stock of Ward</p>
  <?php if ($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
  <form method="post">
    <label>Username <input type="text" name="username" required autofocus></label>
    <label>Password <input type="password" name="password" required></label>
    <button type="submit">Log in</button>
  </form>
  <p class="hint" style="margin-top:24px;"><a href="privacy.php">Privacy Policy</a> · <a href="terms.php">Terms of Service</a></p>
</div>
</body>
</html>
