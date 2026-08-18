<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/oura.php';
require_login();
start_session();

$pdo = get_db();
$active = 'daily';
$error = null;

if (isset($_GET['error'])) {
    $error = 'Oura authorization was denied or cancelled.';
} elseif (!isset($_GET['code']) || !isset($_GET['state'])) {
    $error = 'Missing authorization code — try connecting again.';
} elseif (!isset($_SESSION['oura_oauth_state']) || $_GET['state'] !== $_SESSION['oura_oauth_state']) {
    $error = 'Security check failed (state mismatch) — try connecting again.';
} else {
    unset($_SESSION['oura_oauth_state']);
    $result = oura_token_request([
        'grant_type' => 'authorization_code',
        'code' => $_GET['code'],
        'redirect_uri' => OURA_REDIRECT_URI,
        'client_id' => OURA_CLIENT_ID,
        'client_secret' => OURA_CLIENT_SECRET,
    ]);
    if (!$result || !isset($result['access_token'])) {
        $error = 'Oura didn\'t return a valid token. Double-check OURA_CLIENT_ID/SECRET and the redirect URI match exactly.';
    } else {
        oura_save_tokens($pdo, $result['access_token'], $result['refresh_token'], $result['expires_in']);
        oura_record_attempt($pdo, true);
    }
}
?>
<!doctype html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>WardStock — Oura</title>
<link rel="stylesheet" href="style.css"></head>
<body>
<div class="wrap">
  <header class="topbar"><h1>Oura</h1><a class="btn-link" href="daily.php">← Back to Daily Log</a></header>
  <?php include __DIR__ . '/partials_nav.php'; ?>
  <?php if ($error): ?>
    <p class="error"><?= htmlspecialchars($error) ?></p>
    <p><a class="btn" href="oura_connect.php">Try again</a></p>
  <?php else: ?>
    <p class="notice notice-success">✓ Oura connected.</p>
    <p><a class="btn" href="oura_sync.php">Pull data now</a></p>
  <?php endif; ?>
</div>
</body>
</html>
