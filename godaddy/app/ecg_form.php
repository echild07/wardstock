<?php
// EKG (Kardia) recording — create/edit/detail, all in one page, same
// pattern as every other WardStock section (incident_form.php etc. —
// there's no separate read-only "detail" page anywhere in this app,
// the form IS the record's page).
//
// Scope note (Aug 2026): this is the GoDaddy-side slice of
// homeassistant/EKG_DESIGN.md — manual entry only. That design doc's
// automated PDF-parsing pipeline (versioned parser adapters, per-field
// extraction confidence, OCR fallback) is real, substantial work not
// attempted here; this page is the doc's "confirmation screen" with the
// extraction step ahead of it skipped — Ward reads the Kardia PDF
// himself and fills in what it says, then optionally attaches the PDF
// itself as the preserved original artifact. Symptom capture is also
// simplified from the doc's per-symptom intensity+onset-time model down
// to this app's existing single shared intensity scale (same idea
// incidents.php already uses for anxiety_intensity) — multiple symptom
// codes, one overall 0-10 severity, not one each.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_login();

$pdo = get_db();
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$recording = null;
if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM ecg_recordings WHERE id = ?');
    $stmt->execute([$id]);
    $recording = $stmt->fetch();
    if (!$recording) { header('Location: ecg.php'); exit; }
}

$determinationCodes = [
    '' => '— not yet set —',
    'normal_sinus_rhythm' => 'Normal Sinus Rhythm',
    'possible_atrial_fibrillation' => 'Possible Atrial Fibrillation',
    'bradycardia' => 'Bradycardia',
    'tachycardia' => 'Tachycardia',
    'sinus_rhythm_with_pvcs' => 'Sinus Rhythm with PVCs',
    'sinus_rhythm_with_sve' => 'Sinus Rhythm with Supraventricular Ectopy',
    'sinus_rhythm_with_wide_qrs' => 'Sinus Rhythm with Wide QRS',
    'unclassified' => 'Unclassified',
    'unreadable' => 'Unreadable',
    'other' => 'Other (see exact wording below)',
];
$reasons = [
    'periodic_baseline' => 'Periodic baseline',
    'symptom_capture' => 'Symptom capture',
    'post_exertion' => 'Post-exertion',
    'medication_change' => 'Medication change',
    'clinician_requested' => 'Clinician requested',
    'repeat_after_unreadable' => 'Repeat after unreadable',
    'other' => 'Other',
];
$symptomCodes = [
    'chest_pressure' => 'Chest pressure',
    'chest_pain' => 'Chest pain',
    'left_arm_discomfort' => 'Left arm discomfort',
    'right_arm_discomfort' => 'Right arm discomfort',
    'bilateral_arm_aching' => 'Bilateral arm aching',
    'pounding_heartbeat' => 'Pounding heartbeat',
    'palpitations' => 'Palpitations',
    'irregular_heartbeat_sensation' => 'Irregular heartbeat sensation',
    'shortness_of_breath' => 'Shortness of breath',
    'dizziness' => 'Dizziness',
    'lightheadedness' => 'Lightheadedness',
    'fainting' => 'Fainting',
    'fatigue' => 'Fatigue',
    'sweating' => 'Sweating',
    'nausea' => 'Nausea',
    'jitteriness' => 'Jitteriness',
    'anxiety_or_dread' => 'Anxiety or dread',
    'other' => 'Other',
];

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete']) && $id) {
        // ecg_artifacts rows cascade via the FK's ON DELETE CASCADE — no
        // separate cleanup needed here.
        $stmt = $pdo->prepare('DELETE FROM ecg_recordings WHERE id = ?');
        $stmt->execute([$id]);
        header('Location: ecg.php');
        exit;
    }

    if (isset($_POST['delete_artifact']) && $id) {
        $stmt = $pdo->prepare('DELETE FROM ecg_artifacts WHERE id = ? AND recording_id = ?');
        $stmt->execute([(int)$_POST['delete_artifact'], $id]);
        header('Location: ecg_form.php?id=' . $id);
        exit;
    }

    $symptoms = array_values(array_intersect($_POST['symptoms'] ?? [], array_keys($symptomCodes)));

    $fields = [
        'recorded_at' => $_POST['recorded_at'] ?? '',
        'device_product' => in_array($_POST['device_product'] ?? '', ['KardiaMobile', 'KardiaMobile 6L'], true) ? $_POST['device_product'] : 'KardiaMobile',
        'lead_configuration' => in_array($_POST['lead_configuration'] ?? '', ['single_lead_i', 'six_lead_limb', 'unknown'], true) ? $_POST['lead_configuration'] : 'single_lead_i',
        'duration_seconds' => (($_POST['duration_seconds'] ?? '') === '' ? null : (float)$_POST['duration_seconds']),
        'average_heart_rate_bpm' => (($_POST['average_heart_rate_bpm'] ?? '') === '' ? null : (int)$_POST['average_heart_rate_bpm']),
        'determination_code' => (($_POST['determination_code'] ?? '') === '' ? null : $_POST['determination_code']),
        'determination_text' => trim($_POST['determination_text'] ?? '') ?: null,
        'signal_quality' => in_array($_POST['signal_quality'] ?? '', ['acceptable', 'poor', 'unreadable', 'unknown'], true) ? $_POST['signal_quality'] : 'unknown',
        'recording_reason' => array_key_exists($_POST['recording_reason'] ?? '', $reasons) ? $_POST['recording_reason'] : 'periodic_baseline',
        'symptoms_present' => $symptoms ? 1 : 0,
        'symptoms_json' => json_encode(array_map(fn($code) => ['code' => $code, 'intensity_0_10' => (($_POST['symptom_intensity'] ?? '') === '' ? null : (int)$_POST['symptom_intensity'])], $symptoms)),
        'activity_before' => trim($_POST['activity_before'] ?? '') ?: null,
        'rest_minutes_before' => (($_POST['rest_minutes_before'] ?? '') === '' ? null : (int)$_POST['rest_minutes_before']),
        'related_incident_id' => (($_POST['related_incident_id'] ?? '') === '' ? null : (int)$_POST['related_incident_id']),
        'notes' => trim($_POST['notes'] ?? '') ?: null,
        'clinician_reviewed' => isset($_POST['clinician_reviewed']) ? 1 : 0,
        'clinician_interpretation' => trim($_POST['clinician_interpretation'] ?? '') ?: null,
        'clinician_reviewer_name' => trim($_POST['clinician_reviewer_name'] ?? '') ?: null,
        'clinician_reviewed_at' => (($_POST['clinician_reviewed_at'] ?? '') === '' ? null : $_POST['clinician_reviewed_at']),
    ];

    if ($fields['recorded_at'] === '') {
        $error = 'Recording date/time is required — nothing else was lost, it\'s still filled in below.';
    } else {
        if ($id) {
            $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($fields)));
            $stmt = $pdo->prepare("UPDATE ecg_recordings SET $set WHERE id = :id");
            $fields['id'] = $id;
            $stmt->execute($fields);
            $savedId = $id;
        } else {
            $cols = implode(', ', array_keys($fields));
            $placeholders = implode(', ', array_map(fn($k) => ":$k", array_keys($fields)));
            $stmt = $pdo->prepare("INSERT INTO ecg_recordings ($cols) VALUES ($placeholders)");
            $stmt->execute($fields);
            $savedId = (int)$pdo->lastInsertId();
        }
        // Re-point $id at the now-saved row so a PDF-validation failure
        // below (which intentionally doesn't redirect, so $error stays
        // visible) redisplays this as an edit of the record that really
        // does now exist, not a blank "New" form — the record was already
        // committed above regardless of what happens to the file.
        $id = $savedId;
        $stmt = $pdo->prepare('SELECT * FROM ecg_recordings WHERE id = ?');
        $stmt->execute([$id]);
        $recording = $stmt->fetch(); // so the header below reads "Edit", not "New", if a PDF error keeps us on this page

        // Optional PDF artifact — never required (Ward may log a recording
        // before he's exported/shared the PDF from the Kardia app, or may
        // never bother for a routine baseline). Validated by real MIME
        // sniffing (finfo), not just the client-supplied Content-Type,
        // and bounded to a generous-but-sane size — a Kardia report PDF
        // is a few hundred KB at most.
        if (isset($_FILES['pdf']) && $_FILES['pdf']['error'] === UPLOAD_ERR_OK && $_FILES['pdf']['size'] > 0) {
            $maxBytes = 15 * 1024 * 1024;
            if ($_FILES['pdf']['size'] > $maxBytes) {
                $error = 'PDF too large (max 15MB) — the recording itself was saved, just re-attach a smaller file.';
            } else {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($finfo, $_FILES['pdf']['tmp_name']);
                finfo_close($finfo);
                if ($mime !== 'application/pdf') {
                    $error = 'That file isn\'t a PDF (detected type: ' . htmlspecialchars($mime) . ') — the recording itself was saved, just re-attach the real Kardia PDF.';
                } else {
                    $bytes = file_get_contents($_FILES['pdf']['tmp_name']);
                    $sha256 = hash('sha256', $bytes);
                    try {
                        $stmt = $pdo->prepare('INSERT INTO ecg_artifacts (recording_id, artifact_type, original_filename, mime_type, byte_size, sha256, file_blob)
                                                VALUES (?, ?, ?, ?, ?, ?, ?)');
                        $stmt->execute([$savedId, 'kardia_pdf_report', $_FILES['pdf']['name'], $mime, strlen($bytes), $sha256, $bytes]);
                    } catch (PDOException $e) {
                        // uniq_ecg_recording_sha — same file already attached to this
                        // recording. Not an error worth surfacing; re-uploading the
                        // same PDF twice is a harmless no-op, not a mistake to correct.
                        if (strpos($e->getMessage(), 'uniq_ecg_recording_sha') === false) throw $e;
                    }
                }
            }
        }

        if (!$error) {
            header('Location: ecg_form.php?id=' . $savedId . '&saved=1');
            exit;
        }
    }
}

$formData = ($error && isset($fields)) ? $fields : $recording;
function val($row, $key, $default = '') { return $row ? htmlspecialchars($row[$key] ?? $default) : $default; }
function sel($row, $key, $option, $default = '') { $cur = $row ? ($row[$key] ?? $default) : $default; return (string)$cur === (string)$option ? 'selected' : ''; }

$symptomsChecked = [];
$symptomIntensity = '';
if ($formData && !empty($formData['symptoms_json'])) {
    $decoded = json_decode($formData['symptoms_json'], true);
    if (is_array($decoded)) {
        foreach ($decoded as $s) {
            $symptomsChecked[] = $s['code'] ?? '';
            if (isset($s['intensity_0_10'])) $symptomIntensity = $s['intensity_0_10'];
        }
    }
}

$artifacts = $id ? $pdo->prepare('SELECT id, original_filename, mime_type, byte_size, created_at FROM ecg_artifacts WHERE recording_id = ? ORDER BY created_at') : null;
if ($artifacts) { $artifacts->execute([$id]); $artifacts = $artifacts->fetchAll(); } else { $artifacts = []; }

$recentIncidents = $pdo->query('SELECT id, occurred_at, category FROM incidents ORDER BY occurred_at DESC LIMIT 50')->fetchAll();

$active = 'ecg';
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>WardStock — <?= $recording ? 'Edit EKG Recording' : 'New EKG Recording' ?></title>
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
      <h1><?= $recording ? 'Edit EKG Recording' : 'New EKG Recording' ?></h1>
    </div>
    <a class="btn-link" href="ecg.php">← Back to EKG</a>
  </header>
  <?php include __DIR__ . '/partials_nav.php'; ?>

  <p class="hint">This system stores and organizes personal EKG recordings. It does not diagnose medical conditions, rule out a heart attack, or replace professional medical evaluation. Kardia's automated determination is preserved as vendor-supplied information and may require clinician review.</p>

  <?php if (isset($_GET['saved'])): ?><p class="notice notice-success">✓ Saved.</p><?php endif; ?>
  <?php if ($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>

  <form method="post" enctype="multipart/form-data">
    <fieldset>
      <legend>Recording</legend>
      <label>Date/time <input type="datetime-local" name="recorded_at" value="<?= $formData ? htmlspecialchars(str_replace(' ', 'T', substr($formData['recorded_at'], 0, 16))) : '' ?>" required></label>
      <div class="grid3">
        <label>Device
          <select name="device_product">
            <?php foreach (['KardiaMobile', 'KardiaMobile 6L'] as $d): ?>
              <option value="<?= $d ?>" <?= sel($formData, 'device_product', $d, 'KardiaMobile') ?>><?= $d ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Leads
          <select name="lead_configuration">
            <option value="single_lead_i" <?= sel($formData, 'lead_configuration', 'single_lead_i', 'single_lead_i') ?>>Single lead (I)</option>
            <option value="six_lead_limb" <?= sel($formData, 'lead_configuration', 'six_lead_limb', 'single_lead_i') ?>>Six lead (I, II, III, aVR, aVL, aVF)</option>
            <option value="unknown" <?= sel($formData, 'lead_configuration', 'unknown', 'single_lead_i') ?>>Unknown</option>
          </select>
        </label>
        <label>Duration (seconds) <input type="number" step="0.1" name="duration_seconds" value="<?= val($formData, 'duration_seconds') ?>" placeholder="30"></label>
      </div>
    </fieldset>

    <fieldset>
      <legend>Result</legend>
      <div class="grid3">
        <label>Average heart rate (bpm) <input type="number" name="average_heart_rate_bpm" value="<?= val($formData, 'average_heart_rate_bpm') ?>"></label>
        <label>Kardia determination
          <select name="determination_code">
            <?php foreach ($determinationCodes as $code => $label): ?>
              <option value="<?= htmlspecialchars($code) ?>" <?= sel($formData, 'determination_code', $code) ?>><?= htmlspecialchars($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Signal quality
          <select name="signal_quality">
            <?php foreach (['acceptable' => 'Acceptable', 'poor' => 'Poor', 'unreadable' => 'Unreadable', 'unknown' => 'Unknown'] as $q => $label): ?>
              <option value="<?= $q ?>" <?= sel($formData, 'signal_quality', $q, 'unknown') ?>><?= $label ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>
      <label>Exact wording from the Kardia report (kept separate from the normalized value above) <input type="text" name="determination_text" value="<?= val($formData, 'determination_text') ?>" placeholder="e.g. Normal Sinus Rhythm"></label>
    </fieldset>

    <fieldset>
      <legend>Why this recording</legend>
      <label>Reason
        <select name="recording_reason">
          <?php foreach ($reasons as $code => $label): ?>
            <option value="<?= $code ?>" <?= sel($formData, 'recording_reason', $code, 'periodic_baseline') ?>><?= $label ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <p class="hint">A normal, unclassified, or unreadable personal EKG does not establish that chest pain, chest pressure, arm discomfort, shortness of breath, fainting, or other concerning symptoms are safe or non-cardiac.</p>
      <div class="med-list">
        <?php foreach ($symptomCodes as $code => $label): ?>
          <label class="checkbox-row med-item">
            <input type="checkbox" name="symptoms[]" value="<?= $code ?>" <?= in_array($code, $symptomsChecked, true) ? 'checked' : '' ?>>
            <?= $label ?>
          </label>
        <?php endforeach; ?>
      </div>
      <label>Overall symptom intensity (0–10, if any symptoms checked above)
        <input type="number" min="0" max="10" name="symptom_intensity" value="<?= htmlspecialchars($symptomIntensity) ?>">
      </label>
      <div class="grid3">
        <label>Activity before recording <input type="text" name="activity_before" value="<?= val($formData, 'activity_before') ?>" placeholder="e.g. seated rest"></label>
        <label>Rest minutes before <input type="number" name="rest_minutes_before" value="<?= val($formData, 'rest_minutes_before') ?>"></label>
        <label>Related incident (optional)
          <select name="related_incident_id">
            <option value="">— none —</option>
            <?php foreach ($recentIncidents as $inc): ?>
              <option value="<?= (int)$inc['id'] ?>" <?= sel($formData, 'related_incident_id', $inc['id']) ?>>
                <?= htmlspecialchars(date('M j, Y g:i A', strtotime($inc['occurred_at']))) ?> — <?= htmlspecialchars(ucfirst($inc['category'])) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
      </div>
      <label>Notes <textarea name="notes" rows="3"><?= val($formData, 'notes') ?></textarea></label>
    </fieldset>

    <fieldset>
      <legend>Original PDF</legend>
      <?php if ($artifacts): ?>
        <?php foreach ($artifacts as $a): ?>
          <div class="add-another-row">
            <a class="btn-link" href="ecg_download.php?artifact_id=<?= (int)$a['id'] ?>">⬇ <?= htmlspecialchars($a['original_filename']) ?></a>
            <span class="hint"><?= number_format($a['byte_size'] / 1024, 0) ?> KB · added <?= htmlspecialchars(date('M j, Y', strtotime($a['created_at']))) ?></span>
            <?php if ($id): ?>
            <form method="post" style="margin:0;" onsubmit="return confirm('Remove this PDF?');">
              <button type="submit" name="delete_artifact" value="<?= (int)$a['id'] ?>" class="btn-link btn-sm">Remove</button>
            </form>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <p class="hint">No PDF attached yet — optional, but recommended so the original Kardia report is preserved.</p>
      <?php endif; ?>
      <label>Attach Kardia PDF <input type="file" name="pdf" accept="application/pdf"></label>
    </fieldset>

    <fieldset>
      <legend>Clinician review (optional)</legend>
      <label class="checkbox-row"><input type="checkbox" name="clinician_reviewed" <?= ($formData && $formData['clinician_reviewed']) ? 'checked' : '' ?>> Reviewed by a clinician</label>
      <div class="grid3">
        <label>Reviewer name <input type="text" name="clinician_reviewer_name" value="<?= val($formData, 'clinician_reviewer_name') ?>"></label>
        <label>Reviewed on <input type="date" name="clinician_reviewed_at" value="<?= val($formData, 'clinician_reviewed_at') ?>"></label>
      </div>
      <label>Clinician interpretation (kept separate from Kardia's own determination above, never overwrites it) <textarea name="clinician_interpretation" rows="2"><?= val($formData, 'clinician_interpretation') ?></textarea></label>
    </fieldset>

    <div class="form-actions">
      <button type="submit">Save</button>
      <?php if ($id): ?>
        <button type="submit" name="delete" value="1" class="btn-danger" onclick="return confirm('Delete this EKG recording and its attached PDF?');">Delete</button>
      <?php endif; ?>
    </div>
  </form>
</div>
</body>
</html>
