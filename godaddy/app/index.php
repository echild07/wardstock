<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_login();

$pdo = get_db();
$incCount = (int)$pdo->query('SELECT COUNT(*) c FROM incidents')->fetch()['c'];
$dailyCount = (int)$pdo->query('SELECT COUNT(*) c FROM daily_logs')->fetch()['c'];
$therapyCount = (int)$pdo->query('SELECT COUNT(*) c FROM therapy_sessions')->fetch()['c'];

// combined recent-activity feed across all three tables
$recent = $pdo->query("
    (SELECT 'incident' AS type, id, occurred_at AS event_time,
            CONCAT('Intensity ', COALESCE(anxiety_intensity, '?'), '/10') AS headline,
            NULL AS raw_sleep_hrs
     FROM incidents)
    UNION ALL
    (SELECT 'daily' AS type, id, log_date AS event_time,
            CONCAT('Sleep ', COALESCE(sleep_duration_hrs, '?'), 'h') AS headline,
            sleep_duration_hrs AS raw_sleep_hrs
     FROM daily_logs)
    UNION ALL
    (SELECT 'therapy' AS type, id, session_date AS event_time,
            CONCAT(UPPER(LEFT(session_type,1)), SUBSTRING(session_type,2), ' session') AS headline,
            NULL AS raw_sleep_hrs
     FROM therapy_sessions)
    ORDER BY event_time DESC
    LIMIT 10
")->fetchAll();
foreach ($recent as &$r) {
    if ($r['type'] === 'daily' && $r['raw_sleep_hrs'] !== null) {
        $r['headline'] = 'Sleep ' . fmt_hours_minutes($r['raw_sleep_hrs']);
    }
}
unset($r);

$typeMeta = [
    'incident' => ['label' => 'Incident', 'href' => 'incident_form.php', 'class' => 'tag-incident'],
    'daily' => ['label' => 'Daily Log', 'href' => 'daily_form.php', 'class' => 'tag-daily'],
    'therapy' => ['label' => 'Therapy', 'href' => 'therapy_form.php', 'class' => 'tag-therapy'],
];

// ---------- 7-day summary: current date first, going back 6 days ----------
$days = [];
for ($i = 0; $i <= 6; $i++) {
    $days[] = (new DateTime('today'))->modify("-$i days")->format('Y-m-d');
}
$oldestDay = min($days);

$incByDay = [];
$stmt = $pdo->prepare("SELECT DATE(occurred_at) d, COUNT(*) c FROM incidents WHERE occurred_at >= ? GROUP BY DATE(occurred_at)");
$stmt->execute([$oldestDay . ' 00:00:00']);
foreach ($stmt->fetchAll() as $row) { $incByDay[$row['d']] = (int)$row['c']; }

$dailyByDay = [];
$stmt = $pdo->prepare('SELECT * FROM daily_logs WHERE log_date >= ?');
$stmt->execute([$oldestDay]);
foreach ($stmt->fetchAll() as $row) { $dailyByDay[$row['log_date']] = $row; }

$allMeds = $pdo->query("SELECT * FROM medications WHERE med_type = 'scheduled' ORDER BY sort_order")->fetchAll();
function meds_valid_on($allMeds, $day) {
    $ids = [];
    foreach ($allMeds as $m) {
        if (medication_due_on($m, $day)) $ids[] = (int)$m['id'];
    }
    return $ids;
}

$schedules = $pdo->query('SELECT * FROM therapy_schedules WHERE active = 1')->fetchAll();
$therapyByDay = [];
$stmt = $pdo->prepare('SELECT * FROM therapy_sessions WHERE session_date >= ?');
$stmt->execute([$oldestDay . ' 00:00:00']);
foreach ($stmt->fetchAll() as $row) {
    $d = date('Y-m-d', strtotime($row['session_date']));
    $therapyByDay[$d][$row['session_type']] = $row;
}
function therapy_due_types($schedules, $day) {
    $due = [];
    foreach ($schedules as $s) {
        if ($day < $s['start_date']) continue;
        $diffDays = (strtotime($day) - strtotime($s['start_date'])) / 86400;
        if ($diffDays >= 0 && $diffDays % $s['frequency_days'] == 0) $due[] = $s['session_type'];
    }
    return array_unique($due);
}

// Simple native icons (no external library) prefixed to each pill so the
// dashboard reads faster on a small screen — category is icon, text is
// just the value/status.
$icons = [
    'incident' => '℞',
    'exercise' => '🏋️',
    'caffeine' => '☕',
    'alcohol' => '🍷',
    'medication' => '💊',
    'weight' => '⚖️',
    'mind' => '🧠',
];

function pill($ok, $label, $href, $extraClass = '') {
    $cls = $ok ? 'pill pill-good' : 'pill pill-bad';
    if ($extraClass) $cls .= ' ' . $extraClass;
    return '<a class="' . $cls . '" href="' . htmlspecialchars($href) . '">' . htmlspecialchars($label) . '</a>';
}
function fmt_amount($v) { return rtrim(rtrim(number_format((float)$v, 1), '0'), '.'); }
function consumption_pill($value, $href, $icon) {
    if ($value === null) {
        return '<a class="pill pill-zero" href="' . htmlspecialchars($href) . '">' . $icon . '</a>';
    }
    $v = (float)$value;
    if ($v < 1) $cls = 'pill-zero';
    elseif ($v <= 2) $cls = 'pill-good';
    elseif ($v <= 4) $cls = 'pill-neutral';
    else $cls = 'pill-bad';
    return '<a class="pill ' . $cls . '" href="' . htmlspecialchars($href) . '">' . $icon . ' ' . htmlspecialchars(fmt_amount($v)) . '</a>';
}
// Three states now instead of two: nothing due (green), due but nothing
// entered at all (red — no "missing" text, just the icon), due and some
// but not all taken (yellow), due and all taken (green).
function medication_pill($log, $validIds, $href, $icon) {
    if (!$validIds) return '<a class="pill pill-good" href="' . htmlspecialchars($href) . '">' . $icon . '</a>';
    $taken = [];
    if ($log && $log['medications_taken_json']) {
        $d = json_decode($log['medications_taken_json'], true);
        if (is_array($d)) $taken = array_map('intval', $d);
    }
    $takenDue = array_intersect($validIds, $taken);
    if (!$takenDue) $cls = 'pill-bad';
    elseif (count($takenDue) < count($validIds)) $cls = 'pill-neutral';
    else $cls = 'pill-good';
    return '<a class="pill ' . $cls . '" href="' . htmlspecialchars($href) . '">' . $icon . '</a>';
}
function weight_pill($value, $href, $icon) {
    if ($value === null) return '<a class="pill pill-bad" href="' . htmlspecialchars($href) . '">' . $icon . '</a>';
    return '<a class="pill pill-good" href="' . htmlspecialchars($href) . '">' . $icon . ' ' . htmlspecialchars(fmt_amount($value)) . '</a>';
}
function daily_href($log, $day, $anchor) {
    $base = $log ? 'daily_form.php?id=' . (int)$log['id'] : 'daily_form.php?date=' . $day;
    return $base . '&jump=' . $anchor;
}

$somLabels = [1 => 'Unpleasant', 2 => 'Slightly Unpleasant', 3 => 'Neutral', 4 => 'Slightly Enjoyed', 5 => 'Enjoyed'];
function som_pill($value, $href, $somLabels, $icon) {
    if ($value === null) return '<a class="pill pill-som-unset" href="' . htmlspecialchars($href) . '">' . $icon . '</a>';
    $label = $somLabels[(int)$value] ?? $value;
    return '<a class="pill pill-som-' . (int)$value . '" href="' . htmlspecialchars($href) . '">' . $icon . ' ' . htmlspecialchars($label) . '</a>';
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>WardStock</title>
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
      <h1>WardStock</h1>
    </div>
  </header>
  <?php include __DIR__ . '/partials_nav.php'; ?>

  <h3 class="section-label">Last 7 days</h3>
  <p class="hint" style="margin-top:-10px;"><a href="weight_trend.php">View weight trend (last 2 months) →</a> · <a href="status.php">System status (HA/Node-RED) →</a></p>
  <div class="week-summary">
    <?php foreach ($days as $day):
        $dayIncCount = $incByDay[$day] ?? 0;
        $log = $dailyByDay[$day] ?? null;
        $incHref = 'incident_form.php?date=' . $day;
        $validMedIds = meds_valid_on($allMeds, $day);

        $exerciseOk = $log && ($log['steps'] !== null || $log['exercise_minutes'] !== null || $log['standing_minutes'] !== null);

        $isToday = ($day === date('Y-m-d'));
        $dayLabel = date('D, M j', strtotime($day)) . ($isToday ? ' (today)' : '');
        $dueTypes = therapy_due_types($schedules, $day);
    ?>
    <div class="week-row">
      <span class="week-date"><?= htmlspecialchars($dayLabel) ?></span>
      <div class="week-pills">
        <?= som_pill($log ? $log['state_of_mind'] : null, daily_href($log, $day, 'section-mind'), $somLabels, $icons['mind']) ?>
      </div>
      <div class="week-pills">
        <?= pill($dayIncCount === 0, $icons['incident'] . ($dayIncCount === 0 ? '' : ' ' . $dayIncCount), $incHref, $dayIncCount === 0 ? '' : 'pill-neutral') ?>
        <?= pill($exerciseOk, $icons['exercise'], daily_href($log, $day, 'section-exercise')) ?>
        <?= consumption_pill($log ? $log['caffeine_servings'] : null, daily_href($log, $day, 'section-caffeine'), $icons['caffeine']) ?>
        <?= consumption_pill($log ? $log['alcohol_drinks'] : null, daily_href($log, $day, 'section-alcohol'), $icons['alcohol']) ?>
        <?= medication_pill($log, $validMedIds, daily_href($log, $day, 'section-medication'), $icons['medication']) ?>
        <?= weight_pill($log ? $log['weight'] : null, daily_href($log, $day, 'section-weight'), $icons['weight']) ?>
      </div>
      <?php if ($dueTypes): ?>
      <div class="week-pills">
        <?php foreach ($dueTypes as $type):
            $sessionRow = $therapyByDay[$day][$type] ?? null;
            $label = 'Therapy (' . ucfirst($type) . ')';
            if ($sessionRow) {
                echo pill(true, $label, 'therapy_form.php?id=' . (int)$sessionRow['id']);
            } else {
                echo pill(false, $label . ': due', 'therapy_form.php?date=' . $day . '&type=' . urlencode($type));
            }
        endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>

  <h3 class="section-label">Sections</h3>
  <div class="hub-grid">
    <a class="hub-card" href="incidents.php">
      <div class="hub-card-top">
        <span class="hub-icon tag-incident">⚡</span>
        <span class="hub-count"><?= $incCount ?></span>
      </div>
      <h2>Incidents</h2>
      <p>Anxiety and cardiac episodes — trigger, symptoms, duration, recovery.</p>
    </a>
    <a class="hub-card" href="daily.php">
      <div class="hub-card-top">
        <span class="hub-icon tag-daily">☀</span>
        <span class="hub-count"><?= $dailyCount ?></span>
      </div>
      <h2>Daily Log</h2>
      <p>Sleep, exercise, caffeine, alcohol, medication — as things happen.</p>
    </a>
    <a class="hub-card" href="therapy.php">
      <div class="hub-card-top">
        <span class="hub-icon tag-therapy">◈</span>
        <span class="hub-count"><?= $therapyCount ?></span>
      </div>
      <h2>Therapy</h2>
      <p>Session notes, insights, homework, recovery/evaluation.</p>
    </a>
  </div>

  <h3 class="section-label">Recent activity</h3>
  <?php if (!$recent): ?>
    <p class="empty">Nothing logged yet — pick a section above to add your first entry.</p>
  <?php else: ?>
  <div class="cards">
    <?php foreach ($recent as $r): $meta = $typeMeta[$r['type']]; ?>
      <a class="card" href="<?= $meta['href'] ?>?id=<?= (int)$r['id'] ?>">
        <div class="card-top">
          <span class="card-date"><?= htmlspecialchars(date('M j, Y g:i A', strtotime($r['event_time']))) ?></span>
          <span class="tag <?= $meta['class'] ?>"><?= $meta['label'] ?></span>
        </div>
        <p class="card-trigger"><?= htmlspecialchars($r['headline']) ?></p>
      </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
</body>
</html>
