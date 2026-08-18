<?php
// Intentionally NOT behind require_login() — same reasoning as privacy.php.
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>WardStock — Terms of Service</title>
<link rel="icon" href="favicon-32.png">
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="wrap">
  <header class="topbar">
    <h1>Terms of Service</h1>
    <a class="btn-link" href="login.php">← Back</a>
  </header>

  <p class="hint">Last updated: <?= date('F j, Y') ?></p>

  <fieldset>
    <legend>Single authorized user</legend>
    <p>WardStock is built and operated for the exclusive personal use of its single owner. It is not licensed, offered, or made available to any other individual or organization. No other person is authorized to use this application, connect accounts to it, or access the data it stores, except as the owner may separately and explicitly permit on a case-by-case basis (for example, sharing login access with a spouse for shared household use).</p>
  </fieldset>

  <fieldset>
    <legend>Personal, non-commercial use only</legend>
    <p>This application exists solely for the owner's personal, recreational, non-commercial record-keeping. It is not a medical device, is not intended to diagnose, treat, cure, or prevent any disease or condition, and is not a substitute for professional medical or mental health care. It is not offered for sale, licensing, resale, or any commercial purpose.</p>
  </fieldset>

  <fieldset>
    <legend>No warranty</legend>
    <p>This application is provided "as is," without warranty of any kind, express or implied, including but not limited to fitness for a particular purpose, accuracy, or reliability. As a personal project, it may contain errors, may be modified or discontinued at any time, and is not supported under any service-level agreement.</p>
  </fieldset>

  <fieldset>
    <legend>Third-party services</legend>
    <p>Where this application connects to third-party services (such as the Oura Ring API) at the owner's direction, use of those services remains subject to that third party's own terms of service. This application's use of such services is limited to retrieving the owner's own data for the owner's own personal records.</p>
  </fieldset>

  <fieldset>
    <legend>No liability</legend>
    <p>The owner assumes no liability to any other party for the operation, availability, or content of this application, as it is not intended for use by anyone other than the owner.</p>
  </fieldset>
</div>
</body>
</html>
