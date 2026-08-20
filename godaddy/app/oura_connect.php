<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/oura.php';
require_login();
start_session();

if (!oura_is_configured()) {
    $active = 'daily';
    ?>
    <!doctype html>
    <html>
    <head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>WardStock — Connect Oura</title>
    <link rel="stylesheet" href="style.css"></head>
    <body>
    <div class="wrap">
      <header class="topbar"><h1>Connect Oura</h1><a class="btn-link" href="daily.php">← Back</a></header>
      <?php include __DIR__ . '/partials_nav.php'; ?>
      <p class="error">Oura isn't configured yet.</p>
      <p class="hint">
        To connect your Oura Ring, you first need a free developer application from Oura:
      </p>
      <ol class="hint" style="padding-left:20px;">
        <li>Go to <a href="https://cloud.ouraring.com/oauth/applications" target="_blank">cloud.ouraring.com/oauth/applications</a> and create a new application.</li>
        <li>Set its redirect URI to exactly: <code><?= htmlspecialchars(OURA_REDIRECT_URI) ?></code> (edit this in <code>config/config.php</code> first if your real domain is different, then re-register to match).</li>
        <li>If asked for a Privacy Policy or Terms of Service URL, use: <code>https://emperorschildren.net/Wardstock/privacy.php</code> and <code>https://emperorschildren.net/Wardstock/terms.php</code>.</li>
        <li>Copy the Client ID and Client Secret it gives you into <code>config/config.php</code> — the <code>OURA_CLIENT_ID</code> and <code>OURA_CLIENT_SECRET</code> constants.</li>
        <li>Re-upload <code>config/config.php</code> and come back to this page.</li>
      </ol>
      <p class="hint">If something isn't working after setup, <a href="oura_test.php">run the connection test</a> to see exactly where it's failing.</p>
    </div>
    </body>
    </html>
    <?php
    exit;
}

$pdo = get_db();
$state = bin2hex(random_bytes(16));
$_SESSION['oura_oauth_state'] = $state;

// Expanded Aug 2026 (Fulgrim/wherewhen, homeassistant/PLAN.md §1) — Ward
// wants everything reasonably available from Oura pulled and stored, not
// just what the original 3 endpoints (sleep/daily_activity/daily_readiness)
// needed. 'daily' and 'heartrate' were already requested; workout/session/
// spo2/tag are new. Scope names confirmed via web search against Oura's
// real documented scope list (Aug 2026): the 8 available scopes are
// email, personal, daily, heartrate, workout, tag, session, spo2 — this
// requests all of them. Not verified against a live reconnect yet — if
// Oura's consent screen rejects or silently drops any of these, that's
// the first thing to check before assuming a code bug.
$authUrl = 'https://cloud.ouraring.com/oauth/authorize?' . http_build_query([
    'response_type' => 'code',
    'client_id' => OURA_CLIENT_ID,
    'redirect_uri' => OURA_REDIRECT_URI,
    'scope' => 'email personal daily heartrate workout session tag spo2',
    'state' => $state,
]);

header('Location: ' . $authUrl);
exit;
