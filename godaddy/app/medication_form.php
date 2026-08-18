<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_login();

$pdo = get_db();
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$med = null;
if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM medications WHERE id = ?');
    $stmt->execute([$id]);
    $med = $stmt->fetch();
    if (!$med) { header('Location: medications.php'); exit; }
}

// "Change dosage" flow: prefill from an existing medication's name/type/cadence,
// but blank dosage/start_date, and remember which row to auto-close on save.
$copyFromId = isset($_GET['copy_from']) ? (int)$_GET['copy_from'] : null;
$copyFrom = null;
if ($copyFromId && !$id) {
    $stmt = $pdo->prepare('SELECT * FROM medications WHERE id = ?');
    $stmt->execute([$copyFromId]);
    $copyFrom = $stmt->fetch();
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete']) && $id) {
        $stmt = $pdo->prepare('DELETE FROM medications WHERE id = ?');
        $stmt->execute([$id]);
        header('Location: medications.php');
        exit;
    }

    $fields = [
        'name' => trim($_POST['name'] ?? ''),
        'dosage' => trim($_POST['dosage'] ?? ''),
        'med_type' => ($_POST['med_type'] ?? 'scheduled') === 'as_needed' ? 'as_needed' : 'scheduled',
        'cadence' => trim($_POST['cadence'] ?? 'daily'),
        'frequency_days' => max(1, (int)($_POST['frequency_days'] ?? 1)),
        'start_date' => $_POST['start_date'] ?? '',
        'end_date' => ($_POST['end_date'] === '' ? null : $_POST['end_date']),
    ];

    if ($fields['name'] === '' || $fields['start_date'] === '') {
        $error = 'Name and start date are required.';
    } else {
        if ($id) {
            $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($fields)));
            $stmt = $pdo->prepare("UPDATE medications SET $set WHERE id = :id");
            $fields['id'] = $id;
            $stmt->execute($fields);
        } else {
            $fields['sort_order'] = 0;
            $cols = implode(', ', array_keys($fields));
            $placeholders = implode(', ', array_map(fn($k) => ":$k", array_keys($fields)));
            $stmt = $pdo->prepare("INSERT INTO medications ($cols) VALUES ($placeholders)");
            $stmt->execute($fields);

            // If this was a dosage-change flow, close out the prior row the day before this one starts.
            $closeId = (int)($_POST['close_previous_id'] ?? 0);
            if ($closeId) {
                $newStart = new DateTime($fields['start_date']);
                $newStart->modify('-1 day');
                $stmt2 = $pdo->prepare('UPDATE medications SET end_date = ? WHERE id = ? AND end_date IS NULL');
                $stmt2->execute([$newStart->format('Y-m-d'), $closeId]);
            }
        }
        header('Location: medications.php');
        exit;
    }
}

function val($row, $key, $default = '') { return $row ? htmlspecialchars($row[$key] ?? $default) : $default; }
function sel($row, $key, $option, $default) { $cur = $row ? ($row[$key] ?? $default) : $default; return $cur === $option ? 'selected' : ''; }

$nameVal = $med ? $med['name'] : ($copyFrom ? $copyFrom['name'] : '');
$typeVal = $med ? $med['med_type'] : ($copyFrom ? $copyFrom['med_type'] : 'scheduled');
$cadenceVal = $med ? $med['cadence'] : ($copyFrom ? $copyFrom['cadence'] : 'daily');
$freqVal = $med ? (int)$med['frequency_days'] : ($copyFrom ? (int)$copyFrom['frequency_days'] : 1);
$startVal = $med ? date('Y-m-d', strtotime($med['start_date'])) : date('Y-m-d');
$endVal = $med && $med['end_date'] ? date('Y-m-d', strtotime($med['end_date'])) : '';

$active = 'medications';
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>WardStock — <?= $med ? 'Edit' : 'New' ?> Medication</title>
<link rel="manifest" href="manifest.json">
<link rel="icon" href="favicon-32.png">
<link rel="apple-touch-icon" href="apple-touch-icon.png">
<meta name="theme-color" content="#0f1216">
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="wrap">
  <header class="topbar">
    <h1><?= $med ? 'Edit medication' : ($copyFrom ? 'New dosage' : 'New medication') ?></h1>
    <a class="btn-link" href="medications.php">← Back to list</a>
  </header>
  <?php include __DIR__ . '/partials_nav.php'; ?>

  <?php if ($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
  <?php if ($copyFrom): ?>
    <p class="hint">Starting a new dosage for <?= htmlspecialchars($copyFrom['name']) ?>. Saving this will automatically end the previous dosage the day before this one starts.</p>
  <?php endif; ?>

  <form method="post" class="incident-form">
    <?php if ($copyFrom): ?><input type="hidden" name="close_previous_id" value="<?= (int)$copyFrom['id'] ?>"><?php endif; ?>
    <fieldset>
      <legend>Medication</legend>
      <label>Name <input type="text" name="name" value="<?= htmlspecialchars($nameVal) ?>" required></label>
      <label>Dosage <input type="text" name="dosage" value="<?= $med ? val($med, 'dosage') : '' ?>" placeholder="e.g. 10mg"></label>
      <div class="grid3">
        <label>Type
          <select name="med_type" id="med_type">
            <option value="scheduled" <?= $typeVal === 'scheduled' ? 'selected' : '' ?>>Scheduled</option>
            <option value="as_needed" <?= $typeVal === 'as_needed' ? 'selected' : '' ?>>As needed</option>
          </select>
        </label>
        <label>Cadence (label only) <input type="text" name="cadence" id="cadence_field" value="<?= htmlspecialchars($cadenceVal) ?>" placeholder="daily / weekly / biweekly"></label>
      </div>
      <div class="field-scheduled-only">
        <label>Every how many days is it actually due? <input type="number" min="1" name="frequency_days" id="frequency_days" value="<?= $freqVal ?>"></label>
        <p class="hint">
          This is what actually drives the Daily Log checklist and dashboard — the cadence text above is just a label.
          Quick pick: <a href="#" class="freq-pick" data-days="1" data-label="daily">Daily</a> ·
          <a href="#" class="freq-pick" data-days="7" data-label="weekly">Weekly</a> ·
          <a href="#" class="freq-pick" data-days="14" data-label="biweekly">Biweekly</a> ·
          <a href="#" class="freq-pick" data-days="30" data-label="monthly">Monthly</a>
        </p>
      </div>
    </fieldset>

    <fieldset>
      <legend>Dates</legend>
      <div class="grid3">
        <label>Start date <input type="date" name="start_date" value="<?= htmlspecialchars($startVal) ?>" required></label>
        <label>End date (leave blank if still taking it) <input type="date" name="end_date" value="<?= htmlspecialchars($endVal) ?>"></label>
      </div>
    </fieldset>

    <div class="form-actions">
      <button type="submit">Save medication</button>
      <?php if ($med): ?>
        <button type="submit" name="delete" value="1" class="btn-danger" onclick="return confirm('Delete this medication record entirely? This cannot be undone — consider setting an end date instead if you just stopped taking it.')">Delete</button>
      <?php endif; ?>
    </div>
  </form>

  <?php if ($med && $med['end_date'] === null): ?>
    <p class="hint" style="margin-top:16px;">
      Dosage changed? <a href="medication_form.php?copy_from=<?= (int)$med['id'] ?>">Start a new dosage</a> — this will end the current one automatically.
    </p>
  <?php endif; ?>
</div>
<script>
  document.querySelectorAll('.freq-pick').forEach(function (a) {
    a.addEventListener('click', function (e) {
      e.preventDefault();
      document.getElementById('frequency_days').value = this.dataset.days;
      document.getElementById('cadence_field').value = this.dataset.label;
    });
  });
  function applyMedTypeVisibility() {
    var isAsNeeded = document.getElementById('med_type').value === 'as_needed';
    document.querySelectorAll('.field-scheduled-only').forEach(function (el) {
      el.style.display = isAsNeeded ? 'none' : '';
    });
  }
  document.getElementById('med_type').addEventListener('change', applyMedTypeVisibility);
  applyMedTypeVisibility();
</script>
</body>
</html>
