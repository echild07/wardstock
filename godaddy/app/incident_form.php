<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_login();

$pdo = get_db();
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$incident = null;
if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM incidents WHERE id = ?');
    $stmt->execute([$id]);
    $incident = $stmt->fetch();
    if (!$incident) { header('Location: incidents.php'); exit; }
}

$levels = ['none', 'mild', 'moderate', 'severe'];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete']) && $id) {
        $stmt = $pdo->prepare('DELETE FROM incidents WHERE id = ?');
        $stmt->execute([$id]);
        header('Location: incidents.php');
        exit;
    }

    $category = ($_POST['category'] ?? 'anxiety') === 'cardiac' ? 'cardiac' : 'anxiety';

    $fields = [
        'category' => $category,
        'occurred_at' => $_POST['occurred_at'] ?? '',
        'ended_at' => (($_POST['ended_at'] ?? '') === '' ? null : $_POST['ended_at']),
        'trigger_context' => trim($_POST['trigger_context'] ?? ''),
        'thoughts_before' => trim($_POST['thoughts_before'] ?? ''),
        'chest_sensation' => $_POST['chest_sensation'] ?? 'none',
        'arm_sensation' => $_POST['arm_sensation'] ?? 'none',
        'shoulder_sensation' => $_POST['shoulder_sensation'] ?? 'none',
        'headache_sensation' => $_POST['headache_sensation'] ?? 'none',
        'shaking' => $_POST['shaking'] ?? 'none',
        'anxiety_intensity' => (($_POST['anxiety_intensity'] ?? '') === '' ? null : (int)$_POST['anxiety_intensity']),
        'duration_minutes' => (($_POST['duration_minutes'] ?? '') === '' ? null : (int)$_POST['duration_minutes']),
        'nitroglycerin_taken' => ($category === 'cardiac' && isset($_POST['nitroglycerin_taken'])) ? 1 : 0,
        'what_helped_recovery' => trim($_POST['what_helped_recovery'] ?? ''),
        'differed_from_pattern' => $_POST['differed_from_pattern'] ?? 'unknown',
        'medical_evaluation' => $_POST['medical_evaluation'] ?? 'no',
        'medical_evaluation_notes' => trim($_POST['medical_evaluation_notes'] ?? ''),
        'free_notes' => trim($_POST['free_notes'] ?? ''),
    ];

    if ($fields['occurred_at'] === '') {
        $error = 'Start time is required — nothing else was lost, it\'s still filled in below.';
    } else {
        if ($id) {
            $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($fields)));
            $stmt = $pdo->prepare("UPDATE incidents SET $set WHERE id = :id");
            $fields['id'] = $id;
            $stmt->execute($fields);
        } else {
            $cols = implode(', ', array_keys($fields));
            $placeholders = implode(', ', array_map(fn($k) => ":$k", array_keys($fields)));
            $stmt = $pdo->prepare("INSERT INTO incidents ($cols) VALUES ($placeholders)");
            $stmt->execute($fields);
        }
        header('Location: incident_form.php?id=' . ($id ?: $pdo->lastInsertId()) . '&saved=1');
        exit;
    }
}

// On a validation error, redisplay what was actually submitted rather than
// silently reverting to blank/default values (that used to look like a
// silent failure — the whole form emptied out with only a small error line
// as a clue). $incident still governs "Edit" vs "New" in the title.
$formData = ($error && isset($fields)) ? $fields : $incident;

function val($row, $key, $default = '') { return $row ? htmlspecialchars($row[$key] ?? $default) : $default; }
function sel($row, $key, $option) { $cur = $row ? ($row[$key] ?? 'none') : 'none'; return $cur === $option ? 'selected' : ''; }

$categoryVal = $formData ? ($formData['category'] ?? 'anxiety') : (($_GET['category'] ?? 'anxiety') === 'cardiac' ? 'cardiac' : 'anxiety');

// Context date: the day this form is operating on — the incident's own date when
// editing, or ?date= from the dashboard, or left null so JS fills in local "today".
$prefillDate = $_GET['date'] ?? null;
$contextDate = ($formData && !empty($formData['occurred_at']))
    ? date('Y-m-d', strtotime($formData['occurred_at']))
    : ($prefillDate ?: date('Y-m-d'));

// Occurred/ended values. For a genuinely new incident (no id, no error yet),
// these are seeded with a safe PHP-side default (context date + current server
// time) so the required Start time field is never truly empty even if the
// local-time JS below fails to run for any reason — JS then upgrades it to
// the browser's actual local time when it can.
if ($formData && !empty($formData['occurred_at'])) {
    $occurredValue = date('Y-m-d\TH:i', strtotime($formData['occurred_at']));
} else {
    $occurredValue = $contextDate . 'T' . date('H:i');
}
$endedValue = ($formData && !empty($formData['ended_at'])) ? date('Y-m-d\TH:i', strtotime($formData['ended_at'])) : '';

// Other incidents on the same day, for the table below the form.
$sql = 'SELECT * FROM incidents WHERE DATE(occurred_at) = ?' . ($id ? ' AND id != ?' : '') . ' ORDER BY occurred_at';
$stmt = $pdo->prepare($sql);
$id ? $stmt->execute([$contextDate, $id]) : $stmt->execute([$contextDate]);
$sameDay = $stmt->fetchAll();

// Medication changes in the 7 days leading up to the context date — shown
// read-only, since this is pulled from the Medications section, not typed here.
$rangeStart = (new DateTime($contextDate))->modify('-7 days')->format('Y-m-d');
$stmt = $pdo->prepare('SELECT * FROM medications
    WHERE (start_date BETWEEN ? AND ?) OR (end_date BETWEEN ? AND ?)
    ORDER BY COALESCE(end_date, start_date) DESC');
$stmt->execute([$rangeStart, $contextDate, $rangeStart, $contextDate]);
$medChanges = $stmt->fetchAll();
$medChangeLines = [];
foreach ($medChanges as $m) {
    if ($m['start_date'] >= $rangeStart && $m['start_date'] <= $contextDate) {
        $medChangeLines[] = 'Started ' . $m['name'] . ($m['dosage'] ? ' (' . $m['dosage'] . ')' : '') . ' on ' . date('M j', strtotime($m['start_date']));
    }
    if ($m['end_date'] && $m['end_date'] >= $rangeStart && $m['end_date'] <= $contextDate) {
        $medChangeLines[] = 'Ended ' . $m['name'] . ' on ' . date('M j', strtotime($m['end_date']));
    }
}

// Non-blocking overlap check (offered, Ward confirmed: yes, add it) — flags,
// never prevents saving, since two incidents CAN legitimately overlap (e.g.
// a cardiac episode noticed partway through an ongoing anxiety episode). A
// missing end time is treated as a zero-duration point at the start time,
// not an open-ended range — avoids false-positive warnings for the common
// case of incidents logged without an end time.
function incident_range($row) {
    $start = strtotime($row['occurred_at']);
    $end = !empty($row['ended_at']) ? strtotime($row['ended_at']) : $start;
    return [$start, $end];
}
$overlapWith = [];
if ($formData && !empty($formData['occurred_at'])) {
    [$curStart, $curEnd] = incident_range($formData);
    foreach ($sameDay as $s) {
        [$oStart, $oEnd] = incident_range($s);
        if ($curStart < $oEnd && $oStart < $curEnd) {
            $overlapWith[] = $s;
        }
    }
}

$active = 'incidents';
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>WardStock — <?= $incident ? 'Edit' : 'New' ?> Incident</title>
<link rel="manifest" href="manifest.json">
<link rel="icon" href="favicon-32.png">
<link rel="apple-touch-icon" href="apple-touch-icon.png">
<meta name="theme-color" content="#0f1216">
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="wrap">
  <header class="topbar">
    <h1><?= $incident ? 'Edit incident' : 'New incident' ?></h1>
    <a class="btn-link" href="incidents.php">← Back to list</a>
  </header>
  <?php include __DIR__ . '/partials_nav.php'; ?>

  <?php if (isset($_GET['saved'])): ?>
    <p class="notice notice-success">✓ Saved<?= $incident ? ' — editing "' . htmlspecialchars(date('g:i A', strtotime($incident['occurred_at']))) . '" below' : '' ?>.</p>
  <?php endif; ?>

  <div class="add-another-row">
    <span class="hint">Adding another incident for <?= htmlspecialchars(date('M j', strtotime($contextDate))) ?>:</span>
    <a class="btn btn-sm" href="incident_form.php?category=anxiety&date=<?= $contextDate ?>">+ Anxiety</a>
    <a class="btn btn-sm btn-cardiac" href="incident_form.php?category=cardiac&date=<?= $contextDate ?>">+ Cardiac</a>
  </div>

  <?php if ($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>

  <form method="post" class="incident-form">
    <fieldset>
      <legend>Category</legend>
      <div class="som-scale">
        <label class="som-option cat-anxiety">
          <input type="radio" name="category" value="anxiety" id="cat_anxiety" <?= $categoryVal === 'anxiety' ? 'checked' : '' ?>>
          <span>Anxiety</span>
        </label>
        <label class="som-option cat-cardiac">
          <input type="radio" name="category" value="cardiac" id="cat_cardiac" <?= $categoryVal === 'cardiac' ? 'checked' : '' ?>>
          <span>Cardiac</span>
        </label>
      </div>
    </fieldset>

    <fieldset>
      <legend>When</legend>
      <div class="grid3">
        <label>Start time <input type="datetime-local" id="occurred_at" name="occurred_at" value="<?= htmlspecialchars($occurredValue) ?>" required></label>
        <label>End time (optional) <input type="datetime-local" name="ended_at" value="<?= htmlspecialchars($endedValue) ?>"></label>
        <label>Duration (minutes) <input type="number" min="0" name="duration_minutes" value="<?= val($formData, 'duration_minutes') ?>"></label>
      </div>
      <?php if ($overlapWith): ?>
        <p class="notice-warning">⚠ Overlaps in time with <?= count($overlapWith) === 1 ? 'another incident' : count($overlapWith) . ' other incidents' ?> logged this day:
          <?php foreach ($overlapWith as $i => $o): ?><?= $i > 0 ? ', ' : '' ?><a href="incident_form.php?id=<?= (int)$o['id'] ?>"><?= $o['category'] === 'cardiac' ? 'Cardiac' : 'Anxiety' ?>, <?= htmlspecialchars(date('g:i A', strtotime($o['occurred_at']))) ?><?= $o['ended_at'] ? '–' . htmlspecialchars(date('g:i A', strtotime($o['ended_at']))) : '' ?></a><?php endforeach; ?>
          — not a problem, just worth a look.
        </p>
      <?php endif; ?>
    </fieldset>

    <fieldset class="field-anxiety-only">
      <legend>Trigger &amp; thoughts</legend>
      <label>Trigger / context <textarea name="trigger_context" rows="2"><?= val($formData, 'trigger_context') ?></textarea></label>
      <label>Thoughts immediately before activation <textarea name="thoughts_before" rows="2"><?= val($formData, 'thoughts_before') ?></textarea></label>
    </fieldset>

    <fieldset>
      <legend>Physical symptoms</legend>
      <div class="grid4">
        <label>Chest
          <select name="chest_sensation">
            <?php foreach ($levels as $l): ?><option value="<?= $l ?>" <?= sel($formData, 'chest_sensation', $l) ?>><?= ucfirst($l) ?></option><?php endforeach; ?>
          </select>
        </label>
        <label>Arm
          <select name="arm_sensation">
            <?php foreach ($levels as $l): ?><option value="<?= $l ?>" <?= sel($formData, 'arm_sensation', $l) ?>><?= ucfirst($l) ?></option><?php endforeach; ?>
          </select>
        </label>
        <label>Shoulder
          <select name="shoulder_sensation">
            <?php foreach ($levels as $l): ?><option value="<?= $l ?>" <?= sel($formData, 'shoulder_sensation', $l) ?>><?= ucfirst($l) ?></option><?php endforeach; ?>
          </select>
        </label>
        <label>Headache
          <select name="headache_sensation">
            <?php foreach ($levels as $l): ?><option value="<?= $l ?>" <?= sel($formData, 'headache_sensation', $l) ?>><?= ucfirst($l) ?></option><?php endforeach; ?>
          </select>
        </label>
      </div>
      <div class="grid3 field-anxiety-only">
        <label>Shaking / jitteriness
          <select name="shaking">
            <?php foreach ($levels as $l): ?><option value="<?= $l ?>" <?= sel($formData, 'shaking', $l) ?>><?= ucfirst($l) ?></option><?php endforeach; ?>
          </select>
        </label>
        <label>Subjective anxiety (0–10) <input type="number" min="0" max="10" name="anxiety_intensity" value="<?= val($formData, 'anxiety_intensity') ?>"></label>
      </div>
      <div class="field-cardiac-only">
        <label class="checkbox-row"><input type="checkbox" name="nitroglycerin_taken" <?= ($formData && !empty($formData['nitroglycerin_taken'])) ? 'checked' : '' ?>> Nitroglycerin taken</label>
      </div>
    </fieldset>

    <fieldset>
      <legend>Medication changes (last 7 days)</legend>
      <?php if ($medChangeLines): ?>
        <ul class="med-change-list">
          <?php foreach ($medChangeLines as $line): ?><li><?= htmlspecialchars($line) ?></li><?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p class="hint">No medication starts, dosage changes, or stops in the 7 days before this incident.</p>
      <?php endif; ?>
      <p class="hint">Pulled automatically from <a href="medications.php">Medications</a> — edit there, not here.</p>
    </fieldset>

    <fieldset>
      <legend>Recovery &amp; evaluation</legend>
      <label>What coincided with recovery <textarea name="what_helped_recovery" rows="2"><?= val($formData, 'what_helped_recovery') ?></textarea></label>
      <label>Differed from established pattern?
        <select name="differed_from_pattern">
          <option value="unknown" <?= sel($formData, 'differed_from_pattern', 'unknown') ?>>Unknown</option>
          <option value="no" <?= sel($formData, 'differed_from_pattern', 'no') ?>>No</option>
          <option value="yes" <?= sel($formData, 'differed_from_pattern', 'yes') ?>>Yes</option>
        </select>
      </label>
      <label>Medical evaluation occurred?
        <select name="medical_evaluation">
          <option value="no" <?= sel($formData, 'medical_evaluation', 'no') ?>>No</option>
          <option value="yes" <?= sel($formData, 'medical_evaluation', 'yes') ?>>Yes</option>
        </select>
      </label>
      <label>Medical evaluation notes <textarea name="medical_evaluation_notes" rows="2"><?= val($formData, 'medical_evaluation_notes') ?></textarea></label>
    </fieldset>

    <fieldset>
      <legend>Anything else</legend>
      <label>Free notes <textarea name="free_notes" rows="3"><?= val($formData, 'free_notes') ?></textarea></label>
    </fieldset>

    <div class="form-actions">
      <button type="submit">Save incident</button>
      <?php if ($incident): ?>
        <button type="submit" name="delete" value="1" class="btn-danger" onclick="return confirm('Delete this incident? This cannot be undone.')">Delete</button>
      <?php endif; ?>
    </div>
  </form>

  <h3 class="section-label">Other incidents on <?= htmlspecialchars(date('M j, Y', strtotime($contextDate))) ?></h3>
  <?php if (!$sameDay): ?>
    <p class="empty">No other incidents recorded for this day.</p>
  <?php else: ?>
  <table class="day-table">
    <thead><tr><th>Time</th><th>Category</th><th>Symptoms</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($sameDay as $s): ?>
        <tr>
          <td><?= htmlspecialchars(date('g:i A', strtotime($s['occurred_at']))) ?></td>
          <td><span class="tag <?= $s['category'] === 'cardiac' ? 'tag-cardiac' : 'tag-incident' ?>"><?= $s['category'] === 'cardiac' ? 'Cardiac' : 'Anxiety' ?></span></td>
          <td>
            <?php
              $sym = [];
              foreach (['chest_sensation'=>'Chest','arm_sensation'=>'Arm','shoulder_sensation'=>'Shoulder','headache_sensation'=>'Headache'] as $col => $lbl) {
                  if (($s[$col] ?? 'none') !== 'none') $sym[] = "$lbl: {$s[$col]}";
              }
              echo htmlspecialchars($sym ? implode(', ', $sym) : '—');
            ?>
          </td>
          <td><a class="btn-link" href="incident_form.php?id=<?= (int)$s['id'] ?>">Edit</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<script>
  function applyCategoryVisibility() {
    var isCardiac = document.getElementById('cat_cardiac').checked;
    document.querySelectorAll('.field-anxiety-only').forEach(function (el) {
      el.style.display = isCardiac ? 'none' : '';
    });
    document.querySelectorAll('.field-cardiac-only').forEach(function (el) {
      el.style.display = isCardiac ? '' : 'none';
    });
  }
  document.getElementById('cat_anxiety').addEventListener('change', applyCategoryVisibility);
  document.getElementById('cat_cardiac').addEventListener('change', applyCategoryVisibility);
  applyCategoryVisibility();

  // Upgrade the start-time default to the browser's actual local time on a
  // genuinely fresh "new incident" page load (PHP already seeded a safe
  // server-time fallback so the field is never blank, but the server's clock
  // may be a different timezone — e.g. UTC on shared hosting). Only runs here,
  // never when editing an existing incident or redisplaying after a
  // validation error, so it can't clobber a value you already set.
  var occurredInput = document.getElementById('occurred_at');
  var shouldAutoFillLocalTime = <?= (!$incident && !$error) ? 'true' : 'false' ?>;
  if (occurredInput && shouldAutoFillLocalTime) {
    var prefillDate = <?= $prefillDate ? json_encode($prefillDate) : 'null' ?>;
    var now = new Date();
    var pad = function (n) { return String(n).padStart(2, '0'); };
    var datePart = prefillDate || (now.getFullYear() + '-' + pad(now.getMonth() + 1) + '-' + pad(now.getDate()));
    var timePart = pad(now.getHours()) + ':' + pad(now.getMinutes());
    occurredInput.value = datePart + 'T' + timePart;
  }

  // Start/end/duration stay in sync: entering an end time fills in duration,
  // entering a duration fills in the end time. Whichever you touch last wins.
  var endedInput = document.querySelector('[name="ended_at"]');
  var durationInput = document.querySelector('[name="duration_minutes"]');
  function pad2(n) { return String(n).padStart(2, '0'); }
  function toLocalDatetimeValue(d) {
    return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate()) + 'T' + pad2(d.getHours()) + ':' + pad2(d.getMinutes());
  }
  if (endedInput && durationInput && occurredInput) {
    endedInput.addEventListener('input', function () {
      if (!occurredInput.value || !endedInput.value) return;
      var start = new Date(occurredInput.value);
      var end = new Date(endedInput.value);
      var diffMin = Math.round((end - start) / 60000);
      if (diffMin >= 0) durationInput.value = diffMin;
    });
    durationInput.addEventListener('input', function () {
      if (!occurredInput.value || durationInput.value === '') return;
      var start = new Date(occurredInput.value);
      var end = new Date(start.getTime() + parseInt(durationInput.value, 10) * 60000);
      endedInput.value = toLocalDatetimeValue(end);
    });
  }
</script>
</body>
</html>
