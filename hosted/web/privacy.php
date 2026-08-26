<?php
// Intentionally NOT behind require_login() — this page must be publicly
// viewable without an account, since Oura's app-registration review process
// (and anyone else checking before you connect a service) needs to reach it.
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>WardStock — Privacy Policy</title>
<link rel="icon" href="favicon-32.png">
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="wrap">
  <header class="topbar brand-topbar">
    <a class="brand-logo-link" href="../" title="LeeWard"><img src="leeward-badge.png" alt="LeeWard" width="36" height="36"></a>
    <h1>Privacy Policy</h1>
    <a class="brand-logo-link" href="login.php" title="WardStock"><img src="icon-192.png" alt="WardStock" width="36" height="36"></a>
  </header>
  <p class="hint"><a class="btn-link" href="login.php">← Back</a></p>

  <p class="hint">Last updated: <?= date('F j, Y') ?></p>

  <fieldset>
    <legend>What this is</legend>
    <p>WardStock ("the app") is a private, personal health-tracking application built and used by a single individual (the "owner") for their own personal, non-commercial, recreational record-keeping. It is not offered as a public service, is not distributed to other users, and is not intended for use by anyone other than the owner.</p>
  </fieldset>

  <fieldset>
    <legend>What data is collected</legend>
    <p>The app stores data the owner enters directly, including but not limited to: anxiety and cardiac incident records, daily health metrics (sleep, exercise, caffeine, alcohol, weight, medication), and therapy session notes. It may also, at the owner's direction, retrieve data from third-party services the owner has personally connected — currently the Oura Ring API (sleep, activity, and readiness data) — solely to populate the owner's own records within the app.</p>
    <p>No data is collected from, or about, any person other than the app's owner. There is no user registration, sign-up flow, or mechanism for any other individual to create an account or submit data.</p>
  </fieldset>

  <fieldset>
    <legend>Where data is stored</legend>
    <p>All data is stored in a private MySQL database on the owner's own web hosting account, accessible only through a single password-protected login. It is not shared with, sold to, or made accessible to any third party, advertiser, or analytics service.</p>
  </fieldset>

  <fieldset>
    <legend>Third-party integrations (Oura)</legend>
    <p>If the owner connects an Oura Ring account, the app requests access to that account's sleep, activity, and readiness data via Oura's official OAuth2 API, using access limited to the scopes the owner explicitly authorizes. This access is used only to read the owner's own Oura data and copy relevant values into the owner's own WardStock records. Oura access tokens are stored in the app's private database and are not shared with any other party. The owner may revoke this access at any time through Oura's own account settings or by disconnecting within the app.</p>
  </fieldset>

  <fieldset>
    <legend>No advertising, tracking, or analytics</legend>
    <p>The app does not display advertising, does not use third-party analytics or tracking scripts, and does not sell or share data for marketing purposes of any kind.</p>
  </fieldset>

  <fieldset>
    <legend>Contact</legend>
    <p>This application has a single owner and operator, who can be reached through the means by which this application was shared with you, if applicable.</p>
  </fieldset>
</div>
<?php include __DIR__ . '/partials_footer.php'; ?>
</body>
</html>
