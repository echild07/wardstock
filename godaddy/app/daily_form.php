<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_login();

$pdo = get_db();
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$log = null;
if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM daily_logs WHERE id = ?');
    $stmt->execute([$id]);
    $log = $stmt->fetch();
    if (!$log) { header('Location: daily.php'); exit; }
}

// Determine the date this entry is FOR before querying medications, since the
// checklist must reflect what was actually valid on that date — not "today" —
// so backfilling a past day shows the right medications for that day.
$prefillDate = $_GET['date'] ?? null;
$dateValue = $log ? date('Y-m-d', strtotime($log['log_date'])) : ($prefillDate ?: date('Y-m-d'));

$stmt = $pdo->prepare("SELECT * FROM medications WHERE med_type = 'scheduled' ORDER BY sort_order, name");
$stmt->execute();
$medications = array_values(array_filter($stmt->fetchAll(), fn($m) => medication_due_on($m, $dateValue)));

$takenIds = [];
if ($log && $log['medications_taken_json']) {
    $decoded = json_decode($log['medications_taken_json'], true);
    if (is_array($decoded)) $takenIds = array_map('intval', $decoded);
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete']) && $id) {
        $stmt = $pdo->prepare('DELETE FROM daily_logs WHERE id = ?');
        $stmt->execute([$id]);
        header('Location: daily.php');
        exit;
    }

    $allTaken = isset($_POST['medications_all_taken']) ? 1 : 0;
    if ($allTaken) {
        $takenPost = array_map(fn($m) => (int)$m['id'], $medications);
    } else {
        $takenPost = array_map('intval', $_POST['medications_taken'] ?? []);
    }

    $fields = [
        'log_date' => $_POST['log_date'] ?? '',
        'sleep_duration_hrs' => (($_POST['sleep_hours'] ?? '') === '' && ($_POST['sleep_minutes'] ?? '') === ''
            ? null
            : round((float)($_POST['sleep_hours'] ?: 0) + ((float)($_POST['sleep_minutes'] ?: 0)) / 60, 2)),
        'sleep_efficiency' => ($_POST['sleep_efficiency'] === '' ? null : (int)$_POST['sleep_efficiency']),
        'resting_hr' => ($_POST['resting_hr'] === '' ? null : (int)$_POST['resting_hr']),
        'hrv' => ($_POST['hrv'] === '' ? null : (int)$_POST['hrv']),
        'weight' => ($_POST['weight'] === '' ? null : (float)$_POST['weight']),
        'steps' => ($_POST['steps'] === '' ? null : (int)$_POST['steps']),
        'exercise_minutes' => ($_POST['exercise_minutes'] === '' ? null : (int)$_POST['exercise_minutes']),
        'standing_minutes' => ($_POST['standing_minutes'] === '' ? null : (int)$_POST['standing_minutes']),
        'activity_exertion' => trim($_POST['activity_exertion'] ?? ''),
        'caffeine' => trim($_POST['caffeine'] ?? ''),
        'caffeine_servings' => ($_POST['caffeine_servings'] === '' ? null : (float)$_POST['caffeine_servings']),
        'alcohol' => trim($_POST['alcohol'] ?? ''),
        'alcohol_drinks' => ($_POST['alcohol_drinks'] === '' ? null : (float)$_POST['alcohol_drinks']),
        'medication_notes' => trim($_POST['medication_notes'] ?? ''),
        'medications_all_taken' => $allTaken,
        'medications_taken_json' => json_encode(array_values($takenPost)),
        'mood_rating' => ($_POST['mood_rating'] === '' ? null : (int)$_POST['mood_rating']),
        'state_of_mind' => (($_POST['state_of_mind'] ?? '') === '' ? null : (int)$_POST['state_of_mind']),
        'free_notes' => trim($_POST['free_notes'] ?? ''),
        'night_waking_notes' => trim($_POST['night_waking_notes'] ?? ''),
    ];

    if ($fields['log_date'] === '') {
        $error = 'Date is required.';
    } else {
        if ($id) {
            $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($fields)));
            $stmt = $pdo->prepare("UPDATE daily_logs SET $set WHERE id = :id");
            $fields['id'] = $id;
            $stmt->execute($fields);
        } else {
            $cols = implode(', ', array_keys($fields));
            $placeholders = implode(', ', array_map(fn($k) => ":$k", array_keys($fields)));
            $stmt = $pdo->prepare("INSERT INTO daily_logs ($cols) VALUES ($placeholders)");
            $stmt->execute($fields);
        }
        header('Location: daily.php');
        exit;
    }
}

function val($row, $key, $default = '') { return $row ? htmlspecialchars($row[$key] ?? $default) : $default; }
function sleep_hours_val($row) {
    if (!$row || $row['sleep_duration_hrs'] === null) return '';
    return (int)floor((float)$row['sleep_duration_hrs']);
}
function sleep_minutes_val($row) {
    if (!$row || $row['sleep_duration_hrs'] === null) return '';
    $frac = (float)$row['sleep_duration_hrs'] - floor((float)$row['sleep_duration_hrs']);
    return (int)round($frac * 60);
}
function opt_sel_num($row, $key, $option) {
    $cur = $row ? ($row[$key] ?? null) : null;
    if ($cur === null || $cur === '') return '';
    return (abs((float)$cur - (float)$option) < 0.001) ? 'selected' : '';
}

$caffeineOptions = [0, 0.5, 1, 1.5, 2, 2.5, 3, 3.5, 4, 5, 6];
$alcoholOptions = [0, 0.5, 1, 1.5, 2, 2.5, 3, 4, 5, 6];

// Jump straight to a section when arriving from the dashboard, e.g. daily_form.php?id=5#section-caffeine
$jump = isset($_GET['jump']) ? preg_replace('/[^a-z0-9_-]/', '', $_GET['jump']) : '';

$active = 'daily';
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>WardStock — <?= $log ? 'Edit' : 'New' ?> Daily Log</title>
<link rel="manifest" href="manifest.json">
<link rel="icon" href="favicon-32.png">
<link rel="apple-touch-icon" href="apple-touch-icon.png">
<meta name="theme-color" content="#0f1216">
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="wrap">
  <header class="topbar">
    <h1><?= $log ? 'Edit daily log' : 'New daily log' ?></h1>
    <a class="btn-link" href="daily.php">← Back to list</a>
  </header>
  <?php include __DIR__ . '/partials_nav.php'; ?>

  <?php if ($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>

  <?php if (isset($_GET['oura'])): ?>
    <p class="notice notice-success">✓ Pulled from Oura: <?= htmlspecialchars(str_replace(['sleep_duration_hrs','sleep_efficiency','resting_hr','hrv','steps'], ['sleep duration','sleep efficiency','resting HR','HRV','steps'], $_GET['oura'])) ?>. Add Weight/Caffeine/Alcohol/Medications below, then save.</p>
  <?php endif; ?>

  <p class="hint"><a href="oura_sync.php?date=<?= htmlspecialchars($dateValue) ?>">⬇ Pull from Oura</a> for this date (sleep, resting HR, HRV, steps)</p>

  <form method="post" class="incident-form">
    <fieldset id="section-date">
      <legend>Date</legend>
      <label>Date <input type="date" name="log_date" value="<?= htmlspecialchars($dateValue) ?>" required></label>
    </fieldset>

    <fieldset id="section-sleep">
      <legend>Sleep (from Oura / Apple Health)</legend>
      <div class="grid3">
        <label>Sleep — hours <input type="number" min="0" max="23" name="sleep_hours" value="<?= sleep_hours_val($log) ?>"></label>
        <label>Sleep — minutes <input type="number" min="0" max="59" name="sleep_minutes" value="<?= sleep_minutes_val($log) ?>"></label>
        <label>Efficiency % <input type="number" min="0" max="100" name="sleep_efficiency" value="<?= val($log, 'sleep_efficiency') ?>"></label>
      </div>
      <div class="grid3">
        <label>Resting HR <input type="number" name="resting_hr" value="<?= val($log, 'resting_hr') ?>"></label>
        <label>HRV <input type="number" name="hrv" value="<?= val($log, 'hrv') ?>"></label>
        <label>Mood (0–10) <input type="number" min="0" max="10" name="mood_rating" value="<?= val($log, 'mood_rating') ?>"></label>
      </div>
      <label>Woke in the night? What happened, what were you thinking, why did you wake — <textarea name="night_waking_notes" rows="2"><?= val($log, 'night_waking_notes') ?></textarea></label>
    </fieldset>

    <fieldset id="section-weight">
      <legend>Weight</legend>
      <label>Weight (lbs) <input type="number" step="0.1" name="weight" value="<?= val($log, 'weight') ?>"></label>
      <p class="hint"><a href="weight_trend.php">View trend (last 2 months) →</a></p>
    </fieldset>

    <fieldset id="section-mind">
      <legend>State of mind</legend>
      <p class="hint">A general read on the day — how it felt overall, unpleasant to enjoyed.</p>
      <div class="som-scale">
        <?php
        $somOptions = [1 => 'Unpleasant', 2 => 'Slightly Unpleasant', 3 => 'Neutral', 4 => 'Slightly Enjoyed', 5 => 'Enjoyed'];
        $curSom = $log ? $log['state_of_mind'] : null;
        foreach ($somOptions as $somVal => $somLabel):
        ?>
          <label class="som-option som-<?= $somVal ?>">
            <input type="radio" name="state_of_mind" value="<?= $somVal ?>" <?= ((string)$curSom === (string)$somVal) ? 'checked' : '' ?>>
            <span><?= htmlspecialchars($somLabel) ?></span>
          </label>
        <?php endforeach; ?>
        <label class="som-option som-clear">
          <input type="radio" name="state_of_mind" value="" <?= ($curSom === null || $curSom === '') ? 'checked' : '' ?>>
          <span>Not entered</span>
        </label>
      </div>
    </fieldset>

    <fieldset id="section-exercise">
      <legend>Exercise</legend>
      <div class="grid3">
        <label>Steps <input type="number" min="0" name="steps" value="<?= val($log, 'steps') ?>"></label>
        <label>Exercise minutes <input type="number" min="0" name="exercise_minutes" value="<?= val($log, 'exercise_minutes') ?>"></label>
        <label>Standing minutes <input type="number" min="0" name="standing_minutes" value="<?= val($log, 'standing_minutes') ?>"></label>
      </div>
      <label>Notes <textarea name="activity_exertion" rows="2"><?= val($log, 'activity_exertion') ?></textarea></label>
    </fieldset>

    <fieldset id="section-caffeine">
      <legend>Caffeine</legend>
      <label>Servings — 1 unit ≈ 1 teabag (~45mg). Small coffee or a single espresso shot ≈ 2 units. Medium coffee or a double espresso ≈ 3 units. Large coffee or energy drink ≈ 4+ units.
        <select name="caffeine_servings">
          <option value="">— Not entered —</option>
          <?php foreach ($caffeineOptions as $o): ?>
            <option value="<?= $o ?>" <?= opt_sel_num($log, 'caffeine_servings', $o) ?>><?= $o ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Notes (type, timing) <textarea name="caffeine" rows="2"><?= val($log, 'caffeine') ?></textarea></label>
    </fieldset>

    <fieldset id="section-alcohol">
      <legend>Alcohol</legend>
      <label>Standard drinks (1 beer = 1 glass of wine = 1 shot)
        <select name="alcohol_drinks">
          <option value="">— Not entered —</option>
          <?php foreach ($alcoholOptions as $o): ?>
            <option value="<?= $o ?>" <?= opt_sel_num($log, 'alcohol_drinks', $o) ?>><?= $o ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Notes <textarea name="alcohol" rows="2"><?= val($log, 'alcohol') ?></textarea></label>
    </fieldset>

    <fieldset id="section-medication">
      <legend>Medication</legend>
      <p class="hint">Showing scheduled medications valid on <?= htmlspecialchars(date('M j, Y', strtotime($dateValue))) ?>. Nitroglycerin and other as-needed medications are logged from a Cardiac incident instead, not here.</p>
      <?php if (!$medications): ?>
        <p class="hint">No scheduled medications were active on this date.</p>
      <?php else: ?>
      <label class="checkbox-row">
        <input type="checkbox" id="all_taken" name="medications_all_taken" value="1" <?= ($log && $log['medications_all_taken']) ? 'checked' : '' ?>>
        All taken
      </label>
      <div class="med-list">
        <?php foreach ($medications as $m): ?>
          <label class="checkbox-row med-item">
            <input type="checkbox" class="med-checkbox" name="medications_taken[]" value="<?= (int)$m['id'] ?>"
              <?= in_array((int)$m['id'], $takenIds) ? 'checked' : '' ?>>
            <?= htmlspecialchars($m['name']) ?><?= $m['dosage'] ? ' (' . htmlspecialchars($m['dosage']) . ')' : '' ?>
            <span class="hint">(<?= htmlspecialchars($m['cadence']) ?>)</span>
          </label>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <label>Notes (timing, anything unusual) <textarea name="medication_notes" rows="2"><?= val($log, 'medication_notes') ?></textarea></label>
    </fieldset>

    <fieldset>
      <legend>Anything else</legend>
      <label>Free notes <textarea name="free_notes" rows="3"><?= val($log, 'free_notes') ?></textarea></label>
    </fieldset>

    <div class="form-actions">
      <button type="submit">Save daily log</button>
      <?php if ($log): ?>
        <button type="submit" name="delete" value="1" class="btn-danger" onclick="return confirm('Delete this daily log? This cannot be undone.')">Delete</button>
      <?php endif; ?>
    </div>
  </form>
</div>
<script>
  var allTakenBox = document.getElementById('all_taken');
  if (allTakenBox) {
    allTakenBox.addEventListener('change', function () {
      document.querySelectorAll('.med-checkbox').forEach(function (cb) { cb.checked = false; });
    });
    document.querySelectorAll('.med-checkbox').forEach(function (cb) {
      cb.addEventListener('change', function () { allTakenBox.checked = false; });
    });
  }
  <?php if ($jump): ?>
  var jumpTarget = document.getElementById('<?= $jump ?>');
  if (jumpTarget) jumpTarget.scrollIntoView({ block: 'start' });
  <?php endif; ?>
</script>
</body>
</html>
