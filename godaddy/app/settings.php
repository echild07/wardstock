<?php
// Preferred-timezone setting (Ward, Aug 2026 — a hardcoded America/New_York
// in the HA-side day-grouping logic was the wrong call: "we should have a
// user preference in the app that is pulled to the HA for their preferred
// timezone... let the user set their preferred timezone." Stored in the
// existing generic app_settings key/value table (get_setting/set_setting,
// db.php), same mechanism db_version already uses — no new table needed.
// Exposed to Home Assistant via api/get_shared_config.php (already an
// established "GoDaddy is the source of truth, HA fetches fresh each
// relevant run" pattern — see that file's own comment); the Analysis
// Engine flow fetches it once per run and uses it for every day-boundary
// decision instead of a hardcoded zone (PLAN.md §11).
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_login();

$pdo = get_db();
$active = 'wherewhen';
$subActive = 'settings';

$DEFAULT_TZ = 'America/New_York';
// Real IANA identifiers only (PHP's own list, ~400+ zones) — validated
// server-side rather than trusting free text, since this value flows
// straight into Node-RED's Intl.DateTimeFormat(); an invalid zone there
// would throw and the flow falls back safely, but there's no reason to
// ever let a bad value get that far when PHP can validate it up front.
$validZones = DateTimeZone::listIdentifiers();

$error = '';
$saved = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tz = $_POST['preferred_timezone'] ?? '';
    if (!in_array($tz, $validZones, true)) {
        $error = 'Not a recognized timezone.';
    } else {
        set_setting($pdo, 'preferred_timezone', $tz);
        $saved = true;
    }
}

$current = get_setting($pdo, 'preferred_timezone') ?: $DEFAULT_TZ;
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>WardStock — Settings</title>
<link rel="manifest" href="manifest.json">
<link rel="icon" href="favicon-32.png">
<link rel="apple-touch-icon" href="apple-touch-icon.png">
<meta name="theme-color" content="#0f1216">
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="wrap">
  <header class="topbar">
    <h1>Settings</h1>
  </header>
  <?php include __DIR__ . '/partials_nav.php'; ?>
  <?php include __DIR__ . '/partials_wherewhen_nav.php'; ?>

  <?php if ($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
  <?php if ($saved): ?><p class="hint">Saved. Home Assistant picks this up automatically on its next Analysis Engine run — no separate deploy needed.</p><?php endif; ?>

  <form method="post" class="incident-form">
    <fieldset>
      <legend>Preferred timezone</legend>
      <p class="hint">Used by the wherewhen analysis engine to decide which calendar day a reading belongs to (sleep, incidents, medication events, etc.) — not just how a time is displayed. Chart clock-time display (bedtime/wake, sleep-stage timeline) already follows whatever timezone your own browser is in and doesn't need this setting.</p>
      <label>Timezone
        <select name="preferred_timezone">
          <?php foreach ($validZones as $tz): ?>
            <option value="<?= htmlspecialchars($tz) ?>" <?= $tz === $current ? 'selected' : '' ?>><?= htmlspecialchars($tz) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </fieldset>
    <div class="form-actions">
      <button type="submit">Save</button>
    </div>
  </form>
</div>
</body>
</html>
