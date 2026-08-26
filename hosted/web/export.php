<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_login();

$pdo = get_db();

$lastExport = get_setting($pdo, 'last_export_at');

// ---------- Handle the actual export download ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_export'])) {
    $scope = $_POST['scope'] ?? 'all';
    $types = $_POST['types'] ?? ['incidents', 'daily_logs', 'therapy_sessions'];
    $since = ($scope === 'since_last' && $lastExport) ? $lastExport : null;

    $data = build_export_records($pdo, $since, $types);
    $data = array_merge(['exported_at' => date('c'), 'scope' => $scope, 'since' => $since], $data);

    set_setting($pdo, 'last_export_at', date('Y-m-d H:i:s'));

    $filename = 'wardstock_export_' . date('Y-m-d_His') . '.json';
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
}

// ---------- Counts for the summary shown on the page ----------
// Wrapped in try/catch (unlike the rest of this app's count queries) —
// ecg_recordings is new (Aug 2026) and this page must not 500 on an
// install where the SQL migration hasn't been run yet.
function count_rows($pdo, $table, $since) {
    try {
        if ($since) {
            $stmt = $pdo->prepare("SELECT COUNT(*) c FROM $table WHERE updated_at > ?");
            $stmt->execute([$since]);
        } else {
            $stmt = $pdo->query("SELECT COUNT(*) c FROM $table");
        }
        return (int)$stmt->fetch()['c'];
    } catch (Throwable $e) {
        return 0;
    }
}

$allCounts = [
    'incidents' => count_rows($pdo, 'incidents', null),
    'daily_logs' => count_rows($pdo, 'daily_logs', null),
    'therapy_sessions' => count_rows($pdo, 'therapy_sessions', null),
    'ecg_recordings' => count_rows($pdo, 'ecg_recordings', null),
];
$sinceCounts = $lastExport ? [
    'incidents' => count_rows($pdo, 'incidents', $lastExport),
    'daily_logs' => count_rows($pdo, 'daily_logs', $lastExport),
    'therapy_sessions' => count_rows($pdo, 'therapy_sessions', $lastExport),
    'ecg_recordings' => count_rows($pdo, 'ecg_recordings', $lastExport),
] : null;

$active = 'wherewhen'; // moved under Where When (Fulgrim, PLAN.md §18)
$subActive = 'export';
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>WardStock — Export</title>
<link rel="manifest" href="manifest.json">
<link rel="icon" href="favicon-32.png">
<link rel="apple-touch-icon" href="apple-touch-icon.png">
<meta name="theme-color" content="#0f1216">
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="wrap">
  <header class="topbar">
    <div class="brand">
      <img src="icon-192.png" alt="" width="36" height="36" class="brand-mark">
      <h1>Export</h1>
    </div>
  </header>
  <?php include __DIR__ . '/partials_nav.php'; ?>
  <?php include __DIR__ . '/partials_wherewhen_nav.php'; ?>

  <p class="hint">
    Downloads a single JSON file with all selected records — paste or upload it into a chat
    (e.g. with Claude) for analysis, or open it anywhere JSON is readable.
    <?php if ($lastExport): ?>
      Last export: <?= htmlspecialchars(date('M j, Y g:i A', strtotime($lastExport))) ?>.
    <?php else: ?>
      No export has been run yet.
    <?php endif; ?>
  </p>

  <form method="post" class="incident-form">
    <fieldset>
      <legend>Scope</legend>
      <label class="radio-row">
        <input type="radio" name="scope" value="all" checked>
        All records from the start
        (<?= $allCounts['incidents'] ?> incidents, <?= $allCounts['daily_logs'] ?> daily logs, <?= $allCounts['therapy_sessions'] ?> therapy sessions, <?= $allCounts['ecg_recordings'] ?> EKG recordings)
      </label>
      <label class="radio-row">
        <input type="radio" name="scope" value="since_last" <?= $lastExport ? '' : 'disabled' ?>>
        Only since last export
        <?php if ($lastExport): ?>
          (<?= $sinceCounts['incidents'] ?> incidents, <?= $sinceCounts['daily_logs'] ?> daily logs, <?= $sinceCounts['therapy_sessions'] ?> therapy sessions, <?= $sinceCounts['ecg_recordings'] ?> EKG recordings)
        <?php else: ?>
          (no prior export yet)
        <?php endif; ?>
      </label>
    </fieldset>

    <fieldset>
      <legend>Include</legend>
      <label class="checkbox-row"><input type="checkbox" name="types[]" value="incidents" checked> Incidents</label>
      <label class="checkbox-row"><input type="checkbox" name="types[]" value="daily_logs" checked> Daily Log</label>
      <label class="checkbox-row"><input type="checkbox" name="types[]" value="therapy_sessions" checked> Therapy</label>
      <label class="checkbox-row"><input type="checkbox" name="types[]" value="ecg_recordings" checked> EKG (recording summaries only — PDFs are never included)</label>
    </fieldset>

    <div class="form-actions">
      <button type="submit" name="do_export" value="1">Download export (JSON)</button>
    </div>
  </form>
  <p class="hint">Downloading marks this moment as the new "last export" point, so your next "since last export" pull starts from here.</p>
  <p class="hint">Need to restore data — after a database reset, or catching up a fresh install — from a previous export? <a href="import.php">Import →</a></p>
</div>
<?php include __DIR__ . '/partials_footer.php'; ?>
</body>
</html>
