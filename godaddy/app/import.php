<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_login();

$pdo = get_db();
$active = 'wherewhen'; // moved under Where When (Fulgrim, PLAN.md §18)
$subActive = 'export';
$summary = null;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'No file received, or the upload failed. Try again.';
    } else {
        $json = file_get_contents($_FILES['import_file']['tmp_name']);
        $data = json_decode($json, true);

        if (!is_array($data) || !isset($data['records']) || !is_array($data['records'])) {
            $errors[] = 'This doesn\'t look like a WardStock export file — no "records" array found.';
        } else {
            // The actual per-type upsert logic lives in import_records()
            // (db.php) now — shared with api/bulk_restore.php (Aug 2026,
            // Home Assistant's own restore-from-local-backup flow), so
            // there's one implementation of "how a record gets merged
            // back in," not two that could quietly drift apart.
            try {
                $summary = import_records($pdo, $data['records']);
            } catch (Exception $e) {
                $errors[] = 'Import failed partway through — nothing was saved (the whole import is one transaction). Error: ' . $e->getMessage();
            }
        }
    }
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>WardStock — Import</title>
<link rel="manifest" href="manifest.json">
<link rel="icon" href="favicon-32.png">
<link rel="apple-touch-icon" href="apple-touch-icon.png">
<meta name="theme-color" content="#0f1216">
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="wrap">
  <header class="topbar">
    <h1>Import</h1>
    <a class="btn-link" href="export.php">← Back to Export</a>
  </header>
  <?php include __DIR__ . '/partials_nav.php'; ?>
  <?php include __DIR__ . '/partials_wherewhen_nav.php'; ?>

  <p class="hint">
    Restores data from a WardStock export JSON file — for rebuilding after a database reset, or catching up
    a fresh install from an old backup. <strong>Daily Logs</strong> and <strong>Therapy sessions</strong> are matched
    by date (and type, for therapy) — if a matching entry already exists it's updated in place, so re-importing
    the same file twice is safe for those. <strong>Incidents have no reliable matching key and are always inserted
    as new records</strong> — importing the same file twice will create duplicate incidents, so only do this once
    per export, or manually delete the duplicates afterward. <strong>EKG recordings</strong> are matched by
    recording time + device, same idea as therapy sessions; the original PDF is never part of export/import,
    only the recording summary.
  </p>

  <?php foreach ($errors as $e): ?><p class="error"><?= htmlspecialchars($e) ?></p><?php endforeach; ?>

  <?php if ($summary): ?>
    <div class="report-box">
      <p class="notice notice-success">✓ Import complete.</p>
      <ul class="med-change-list">
        <li>Incidents: <?= $summary['counts']['incident']['inserted'] ?> added<?= $summary['counts']['incident']['skipped'] ? ', ' . $summary['counts']['incident']['skipped'] . ' skipped (missing start time)' : '' ?></li>
        <li>Daily Logs: <?= $summary['counts']['daily_log']['inserted'] ?> added, <?= $summary['counts']['daily_log']['updated'] ?> updated<?= $summary['counts']['daily_log']['skipped'] ? ', ' . $summary['counts']['daily_log']['skipped'] . ' skipped (missing date)' : '' ?></li>
        <li>Therapy sessions: <?= $summary['counts']['therapy_session']['inserted'] ?> added, <?= $summary['counts']['therapy_session']['updated'] ?> updated<?= $summary['counts']['therapy_session']['skipped'] ? ', ' . $summary['counts']['therapy_session']['skipped'] . ' skipped (missing date)' : '' ?></li>
        <li>Medications: <?= $summary['counts']['medication']['inserted'] ?> added, <?= $summary['counts']['medication']['updated'] ?> updated<?= $summary['counts']['medication']['skipped'] ? ', ' . $summary['counts']['medication']['skipped'] . ' skipped (missing name/start date)' : '' ?></li>
        <li>Dosage history: <?= $summary['counts']['medication_dosage_history']['inserted'] ?> added<?= $summary['counts']['medication_dosage_history']['skipped'] ? ', ' . $summary['counts']['medication_dosage_history']['skipped'] . ' skipped (already present, or no matching medication)' : '' ?></li>
        <li>EKG recordings: <?= $summary['counts']['ecg_recording']['inserted'] ?> added, <?= $summary['counts']['ecg_recording']['updated'] ?> updated<?= $summary['counts']['ecg_recording']['skipped'] ? ', ' . $summary['counts']['ecg_recording']['skipped'] . ' skipped (missing recorded-at time)' : '' ?> — PDFs are never included in export/import, only the recording summary</li>
      </ul>
      <?php if ($summary['unmatchedMeds']): ?>
        <p class="error">These medication names from the file don't match anything in your current Medications list, so they were skipped when rebuilding the "taken" checklist for affected days: <?= htmlspecialchars(implode(', ', $summary['unmatchedMeds'])) ?>. If they've just been renamed, add them again under Medications and re-import.</p>
      <?php endif; ?>
    </div>
    <p><a class="btn" href="index.php">Go to Dashboard</a></p>
  <?php else: ?>
    <form method="post" enctype="multipart/form-data" class="incident-form">
      <fieldset>
        <legend>Export file</legend>
        <label>Choose a WardStock export (.json) <input type="file" name="import_file" accept=".json,application/json" required></label>
      </fieldset>
      <div class="form-actions">
        <button type="submit">Import</button>
      </div>
    </form>
  <?php endif; ?>
</div>
</body>
</html>
