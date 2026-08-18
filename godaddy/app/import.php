<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_login();

$pdo = get_db();
$active = 'export';
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
            $medLookup = [];
            foreach ($pdo->query('SELECT id, name FROM medications')->fetchAll() as $m) {
                $medLookup[$m['name']] = (int)$m['id'];
            }

            $counts = [
                'incident' => ['inserted' => 0, 'skipped' => 0],
                'daily_log' => ['inserted' => 0, 'updated' => 0, 'skipped' => 0],
                'therapy_session' => ['inserted' => 0, 'updated' => 0, 'skipped' => 0],
                'medication' => ['inserted' => 0, 'updated' => 0, 'skipped' => 0],
            ];
            $unmatchedMeds = [];

            try {
                $pdo->beginTransaction();

                foreach ($data['records'] as $rec) {
                    $type = $rec['record_type'] ?? null;

                    if ($type === 'incident') {
                        if (empty($rec['occurred_at'])) { $counts['incident']['skipped']++; continue; }
                        $cols = ['category', 'occurred_at', 'ended_at', 'trigger_context', 'thoughts_before',
                                 'chest_sensation', 'arm_sensation', 'shoulder_sensation', 'headache_sensation',
                                 'shaking', 'anxiety_intensity', 'duration_minutes', 'nitroglycerin_taken',
                                 'what_helped_recovery', 'differed_from_pattern', 'medical_evaluation',
                                 'medical_evaluation_notes', 'free_notes'];
                        $fields = [];
                        foreach ($cols as $c) { $fields[$c] = array_key_exists($c, $rec) ? $rec[$c] : null; }
                        if (empty($fields['category'])) $fields['category'] = 'anxiety'; // NOT NULL column
                        $colList = implode(', ', array_keys($fields));
                        $placeholders = implode(', ', array_map(fn($k) => ":$k", array_keys($fields)));
                        $stmt = $pdo->prepare("INSERT INTO incidents ($colList) VALUES ($placeholders)");
                        $stmt->execute($fields);
                        $counts['incident']['inserted']++;

                    } elseif ($type === 'daily_log') {
                        if (empty($rec['log_date'])) { $counts['daily_log']['skipped']++; continue; }

                        $cols = ['sleep_duration_hrs', 'sleep_efficiency', 'resting_hr', 'hrv', 'weight',
                                 'steps', 'exercise_minutes', 'standing_minutes', 'activity_exertion', 'caffeine',
                                 'caffeine_servings', 'alcohol', 'alcohol_drinks', 'medication_notes',
                                 'mood_rating', 'state_of_mind', 'free_notes'];

                        $existing = $pdo->prepare('SELECT * FROM daily_logs WHERE log_date = ?');
                        $existing->execute([$rec['log_date']]);
                        $existingRow = $existing->fetch();

                        // Merge, don't overwrite: a partial import (e.g. weight-only history) should
                        // never null out fields it simply doesn't mention on a day that already has
                        // fuller data. Only fields actually present (and non-null) in the import
                        // record replace what's there; everything else keeps its existing value.
                        $fields = ['log_date' => $rec['log_date']];
                        foreach ($cols as $c) {
                            if (array_key_exists($c, $rec) && $rec[$c] !== null) {
                                $fields[$c] = $rec[$c];
                            } elseif ($existingRow) {
                                $fields[$c] = $existingRow[$c];
                            } else {
                                $fields[$c] = null;
                            }
                        }

                        // Medications: only touched if the import record actually specifies them —
                        // an empty/absent medications_taken in the source is not the same thing as
                        // "confirmed nothing was taken," so don't let it erase real existing data.
                        if (array_key_exists('medications_taken', $rec)) {
                            $medIds = [];
                            foreach (($rec['medications_taken'] ?? []) as $name) {
                                if (isset($medLookup[$name])) $medIds[] = $medLookup[$name];
                                else $unmatchedMeds[$name] = true;
                            }
                            $fields['medications_taken_json'] = json_encode(array_values($medIds));
                            $fields['medications_all_taken'] = array_key_exists('medications_all_taken', $rec)
                                ? $rec['medications_all_taken']
                                : ($existingRow['medications_all_taken'] ?? null);
                        } elseif ($existingRow) {
                            $fields['medications_taken_json'] = $existingRow['medications_taken_json'];
                            $fields['medications_all_taken'] = $existingRow['medications_all_taken'];
                        } else {
                            $fields['medications_taken_json'] = json_encode([]);
                            $fields['medications_all_taken'] = null;
                        }

                        if ($existingRow) {
                            $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($fields)));
                            $stmt = $pdo->prepare("UPDATE daily_logs SET $set WHERE id = :id");
                            $fields['id'] = $existingRow['id'];
                            $stmt->execute($fields);
                            $counts['daily_log']['updated']++;
                        } else {
                            $colList = implode(', ', array_keys($fields));
                            $placeholders = implode(', ', array_map(fn($k) => ":$k", array_keys($fields)));
                            $stmt = $pdo->prepare("INSERT INTO daily_logs ($colList) VALUES ($placeholders)");
                            $stmt->execute($fields);
                            $counts['daily_log']['inserted']++;
                        }

                    } elseif ($type === 'therapy_session') {
                        if (empty($rec['session_date'])) { $counts['therapy_session']['skipped']++; continue; }
                        $cols = ['session_type', 'summary', 'insights', 'homework', 'mood_before', 'mood_after', 'free_notes'];

                        $existing = $pdo->prepare('SELECT * FROM therapy_sessions WHERE session_date = ? AND session_type = ?');
                        $existing->execute([$rec['session_date'], $rec['session_type'] ?? 'individual']);
                        $existingRow = $existing->fetch();

                        $fields = ['session_date' => $rec['session_date']];
                        foreach ($cols as $c) {
                            if (array_key_exists($c, $rec) && $rec[$c] !== null) {
                                $fields[$c] = $rec[$c];
                            } elseif ($existingRow) {
                                $fields[$c] = $existingRow[$c];
                            } else {
                                $fields[$c] = ($c === 'session_type') ? 'individual' : null;
                            }
                        }

                        if ($existingRow) {
                            $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($fields)));
                            $stmt = $pdo->prepare("UPDATE therapy_sessions SET $set WHERE id = :id");
                            $fields['id'] = $existingRow['id'];
                            $stmt->execute($fields);
                            $counts['therapy_session']['updated']++;
                        } else {
                            $colList = implode(', ', array_keys($fields));
                            $placeholders = implode(', ', array_map(fn($k) => ":$k", array_keys($fields)));
                            $stmt = $pdo->prepare("INSERT INTO therapy_sessions ($colList) VALUES ($placeholders)");
                            $stmt->execute($fields);
                            $counts['therapy_session']['inserted']++;
                        }

                    } elseif ($type === 'medication') {
                        // Added for the Lucius project's HA disaster-recovery
                        // archive (see homeassistant/PLAN.md §4) — this record
                        // type never appeared in exports before medications
                        // were added to api/pull_manual_data.php. Matched by
                        // (name, start_date) — a reliable natural key for one
                        // specific dosage era of one medication, unlike
                        // incidents which have none.
                        if (empty($rec['name']) || empty($rec['start_date'])) { $counts['medication']['skipped']++; continue; }
                        $cols = ['dosage', 'med_type', 'cadence', 'frequency_days', 'end_date', 'sort_order'];

                        $existing = $pdo->prepare('SELECT * FROM medications WHERE name = ? AND start_date = ?');
                        $existing->execute([$rec['name'], $rec['start_date']]);
                        $existingRow = $existing->fetch();

                        $fields = ['name' => $rec['name'], 'start_date' => $rec['start_date']];
                        foreach ($cols as $c) {
                            if (array_key_exists($c, $rec) && $rec[$c] !== null) {
                                $fields[$c] = $rec[$c];
                            } elseif ($existingRow) {
                                $fields[$c] = $existingRow[$c];
                            } else {
                                $fields[$c] = ($c === 'med_type') ? 'scheduled' : (($c === 'frequency_days') ? 1 : null);
                            }
                        }

                        if ($existingRow) {
                            $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($fields)));
                            $stmt = $pdo->prepare("UPDATE medications SET $set WHERE id = :id");
                            $fields['id'] = $existingRow['id'];
                            $stmt->execute($fields);
                            $counts['medication']['updated']++;
                        } else {
                            $colList = implode(', ', array_keys($fields));
                            $placeholders = implode(', ', array_map(fn($k) => ":$k", array_keys($fields)));
                            $stmt = $pdo->prepare("INSERT INTO medications ($colList) VALUES ($placeholders)");
                            $stmt->execute($fields);
                            $counts['medication']['inserted']++;
                        }
                    }
                }

                $pdo->commit();
                $summary = ['counts' => $counts, 'unmatchedMeds' => array_keys($unmatchedMeds)];
            } catch (Exception $e) {
                $pdo->rollBack();
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

  <p class="hint">
    Restores data from a WardStock export JSON file — for rebuilding after a database reset, or catching up
    a fresh install from an old backup. <strong>Daily Logs</strong> and <strong>Therapy sessions</strong> are matched
    by date (and type, for therapy) — if a matching entry already exists it's updated in place, so re-importing
    the same file twice is safe for those. <strong>Incidents have no reliable matching key and are always inserted
    as new records</strong> — importing the same file twice will create duplicate incidents, so only do this once
    per export, or manually delete the duplicates afterward.
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
