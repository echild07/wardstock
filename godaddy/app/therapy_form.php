<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_login();

$pdo = get_db();
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$session = null;
if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM therapy_sessions WHERE id = ?');
    $stmt->execute([$id]);
    $session = $stmt->fetch();
    if (!$session) { header('Location: therapy.php'); exit; }
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete']) && $id) {
        $stmt = $pdo->prepare('DELETE FROM therapy_sessions WHERE id = ?');
        $stmt->execute([$id]);
        header('Location: therapy.php');
        exit;
    }

    $fields = [
        'session_date' => $_POST['session_date'] ?? '',
        'session_type' => $_POST['session_type'] ?? 'individual',
        'summary' => trim($_POST['summary'] ?? ''),
        'insights' => trim($_POST['insights'] ?? ''),
        'homework' => trim($_POST['homework'] ?? ''),
        'mood_before' => ($_POST['mood_before'] === '' ? null : (int)$_POST['mood_before']),
        'mood_after' => ($_POST['mood_after'] === '' ? null : (int)$_POST['mood_after']),
        'free_notes' => trim($_POST['free_notes'] ?? ''),
    ];

    if ($fields['session_date'] === '') {
        $error = 'Date/time is required.';
    } else {
        if ($id) {
            $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($fields)));
            $stmt = $pdo->prepare("UPDATE therapy_sessions SET $set WHERE id = :id");
            $fields['id'] = $id;
            $stmt->execute($fields);
        } else {
            $cols = implode(', ', array_keys($fields));
            $placeholders = implode(', ', array_map(fn($k) => ":$k", array_keys($fields)));
            $stmt = $pdo->prepare("INSERT INTO therapy_sessions ($cols) VALUES ($placeholders)");
            $stmt->execute($fields);
        }
        header('Location: therapy.php');
        exit;
    }
}

function val($row, $key, $default = '') { return $row ? htmlspecialchars($row[$key] ?? $default) : $default; }
function sel($row, $key, $option, $default) {
    $cur = $row ? ($row[$key] ?? $default) : $default;
    return $cur === $option ? 'selected' : '';
}

$prefillDate = $_GET['date'] ?? null;
$prefillType = $_GET['type'] ?? null;
$dateValue = $session
    ? date('Y-m-d\TH:i', strtotime($session['session_date']))
    : ($prefillDate ? $prefillDate . 'T18:00' : date('Y-m-d\TH:i'));
$active = 'wherewhen'; // moved under Where When (Fulgrim, PLAN.md §18)
$subActive = 'therapy';
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>WardStock — <?= $session ? 'Edit' : 'New' ?> Therapy Session</title>
<link rel="manifest" href="manifest.json">
<link rel="icon" href="favicon-32.png">
<link rel="apple-touch-icon" href="apple-touch-icon.png">
<meta name="theme-color" content="#0f1216">
<link rel="stylesheet" href="style.css">
</head>
<body>
<div class="wrap">
  <header class="topbar">
    <h1><?= $session ? 'Edit session' : 'New session' ?></h1>
    <a class="btn-link" href="therapy.php">← Back to list</a>
  </header>
  <?php include __DIR__ . '/partials_nav.php'; ?>
  <?php include __DIR__ . '/partials_wherewhen_nav.php'; ?>
  <p class="hint"><a href="therapy_schedules.php">Manage recurring schedule →</a></p>

  <?php if ($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>

  <form method="post" class="incident-form">
    <fieldset>
      <legend>Session</legend>
      <div class="grid3">
        <label>Date &amp; time <input type="datetime-local" name="session_date" value="<?= htmlspecialchars($dateValue) ?>" required></label>
        <label>Type
          <select name="session_type">
            <option value="individual" <?= sel($session, 'session_type', 'individual', $prefillType ?: 'individual') ?>>Individual</option>
            <option value="couples" <?= sel($session, 'session_type', 'couples', $prefillType ?: 'individual') ?>>Couples</option>
            <option value="other" <?= sel($session, 'session_type', 'other', $prefillType ?: 'individual') ?>>Other</option>
          </select>
        </label>
      </div>
    </fieldset>

    <fieldset>
      <legend>Mood</legend>
      <div class="grid3">
        <label>Mood before (0–10) <input type="number" min="0" max="10" name="mood_before" value="<?= val($session, 'mood_before') ?>"></label>
        <label>Mood after (0–10) <input type="number" min="0" max="10" name="mood_after" value="<?= val($session, 'mood_after') ?>"></label>
      </div>
    </fieldset>

    <fieldset>
      <legend>Recovery &amp; evaluation</legend>
      <label>Summary — what was covered <textarea name="summary" rows="3"><?= val($session, 'summary') ?></textarea></label>
      <label>Insights / realizations <textarea name="insights" rows="3"><?= val($session, 'insights') ?></textarea></label>
      <label>Homework / next steps <textarea name="homework" rows="2"><?= val($session, 'homework') ?></textarea></label>
    </fieldset>

    <fieldset>
      <legend>Anything else</legend>
      <label>Free notes <textarea name="free_notes" rows="3"><?= val($session, 'free_notes') ?></textarea></label>
    </fieldset>

    <div class="form-actions">
      <button type="submit">Save session</button>
      <?php if ($session): ?>
        <button type="submit" name="delete" value="1" class="btn-danger" onclick="return confirm('Delete this session? This cannot be undone.')">Delete</button>
      <?php endif; ?>
    </div>
  </form>
</div>
</body>
</html>
