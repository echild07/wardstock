<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_login();

$pdo = get_db();
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$sched = null;
if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM wardstock_therapy_schedules WHERE id = ?');
    $stmt->execute([$id]);
    $sched = $stmt->fetch();
    if (!$sched) { header('Location: therapy_schedules.php'); exit; }
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete']) && $id) {
        $stmt = $pdo->prepare('DELETE FROM wardstock_therapy_schedules WHERE id = ?');
        $stmt->execute([$id]);
        header('Location: therapy_schedules.php');
        exit;
    }

    $fields = [
        'session_type' => $_POST['session_type'] ?? 'individual',
        'start_date' => $_POST['start_date'] ?? '',
        'frequency_days' => ($_POST['frequency_days'] === '' ? 7 : (int)$_POST['frequency_days']),
        'active' => isset($_POST['active']) ? 1 : 0,
    ];

    if ($fields['start_date'] === '') {
        $error = 'Start date is required.';
    } else {
        if ($id) {
            $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($fields)));
            $stmt = $pdo->prepare("UPDATE wardstock_therapy_schedules SET $set WHERE id = :id");
            $fields['id'] = $id;
            $stmt->execute($fields);
        } else {
            $cols = implode(', ', array_keys($fields));
            $placeholders = implode(', ', array_map(fn($k) => ":$k", array_keys($fields)));
            $stmt = $pdo->prepare("INSERT INTO wardstock_therapy_schedules ($cols) VALUES ($placeholders)");
            $stmt->execute($fields);
        }
        header('Location: therapy_schedules.php');
        exit;
    }
}

function sel($row, $key, $option, $default) { $cur = $row ? ($row[$key] ?? $default) : $default; return $cur === $option ? 'selected' : ''; }
$startVal = $sched ? date('Y-m-d', strtotime($sched['start_date'])) : app_today($pdo); // fallback was date('Y-m-d') — server default, not Ward's actual today (Aug 2026 fix)
$freqVal = $sched ? (int)$sched['frequency_days'] : 7;
$active = 'wherewhen'; // moved under Where When (Fulgrim, PLAN.md §18)
$subActive = 'therapy';
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>WardStock — <?= $sched ? 'Edit' : 'New' ?> Therapy Schedule</title>
<link rel="manifest" href="manifest.json">
<link rel="icon" href="favicon-32.png">
<link rel="apple-touch-icon" href="apple-touch-icon.png">
<meta name="theme-color" content="#0f1216">
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="wrap">
  <header class="topbar">
    <h1><?= $sched ? 'Edit schedule' : 'New schedule' ?></h1>
    <a class="btn-link" href="therapy_schedules.php">← Back to list</a>
  </header>
  <?php include __DIR__ . '/partials_nav.php'; ?>
  <?php include __DIR__ . '/partials_wherewhen_nav.php'; ?>

  <?php if ($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>

  <form method="post" class="incident-form">
    <fieldset>
      <legend>Schedule</legend>
      <label>Type
        <select name="session_type">
          <option value="individual" <?= sel($sched, 'session_type', 'individual', 'individual') ?>>Individual</option>
          <option value="couples" <?= sel($sched, 'session_type', 'couples', 'individual') ?>>Couples</option>
          <option value="other" <?= sel($sched, 'session_type', 'other', 'individual') ?>>Other</option>
        </select>
      </label>
      <div class="grid3">
        <label>Start date <input type="date" name="start_date" value="<?= htmlspecialchars($startVal) ?>" required></label>
        <label>Every how many days? <input type="number" min="1" name="frequency_days" value="<?= $freqVal ?>" required></label>
      </div>
      <label class="checkbox-row"><input type="checkbox" name="active" <?= (!$sched || $sched['active']) ? 'checked' : '' ?>> Active (show reminders on the dashboard)</label>
    </fieldset>

    <div class="form-actions">
      <button type="submit">Save schedule</button>
      <?php if ($sched): ?>
        <button type="submit" name="delete" value="1" class="btn-danger" onclick="return confirm('Delete this schedule? This cannot be undone.')">Delete</button>
      <?php endif; ?>
    </div>
  </form>
</div>
<?php include __DIR__ . '/partials_footer.php'; ?>
</body>
</html>
