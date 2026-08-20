<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/attention.php';
require_login();

$pdo = get_db();

// Snooze a reminder — POST-only, redirects back rather than rendering, so
// a page refresh after snoozing never re-submits the form.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['snooze_key'])) {
    snooze_reminder($pdo, $_POST['snooze_key']);
    header('Location: index.php#attention');
    exit;
}

$attentionItems = get_attention_items($pdo);
$pendingEventCount = get_pending_event_count($pdo);

$incCount = (int)$pdo->query('SELECT COUNT(*) c FROM incidents')->fetch()['c'];
$dailyCount = (int)$pdo->query('SELECT COUNT(*) c FROM daily_logs')->fetch()['c'];
$therapyCount = (int)$pdo->query('SELECT COUNT(*) c FROM therapy_sessions')->fetch()['c'];

// combined recent-activity feed across all three tables
$recent = $pdo->query("
    (SELECT 'incident' AS type, id, occurred_at AS event_time,
            CASE category
                WHEN 'medical' THEN CONCAT('Medical', IF(medical_evaluation = 'yes', ' — evaluation', ''))
                ELSE CONCAT('Intensity ', COALESCE(anxiety_intensity, '?'), '/10')
            END AS headline,
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

// Blood pressure readings by day (Fulgrim, feature list §1.2) — a day can
// have more than one, grouped here for the dashboard pill (see bp_pill()).
$bpByDay = [];
$stmt = $pdo->prepare('SELECT * FROM blood_pressure_readings WHERE reading_at >= ? ORDER BY reading_at');
$stmt->execute([$oldestDay . ' 00:00:00']);
foreach ($stmt->fetchAll() as $row) { $bpByDay[date('Y-m-d', strtotime($row['reading_at']))][] = $row; }

$allMeds = $pdo->query("SELECT * FROM medications WHERE med_type = 'scheduled' ORDER BY sort_order")->fetchAll();
// meds_valid_on() now lives in attention.php (shared with the attention banner).

$schedules = $pdo->query('SELECT * FROM therapy_schedules WHERE active = 1')->fetchAll();
$therapyByDay = [];
$stmt = $pdo->prepare('SELECT * FROM therapy_sessions WHERE session_date >= ?');
$stmt->execute([$oldestDay . ' 00:00:00']);
foreach ($stmt->fetchAll() as $row) {
    $d = date('Y-m-d', strtotime($row['session_date']));
    $therapyByDay[$d][$row['session_type']] = $row;
}
// therapy_due_types() now lives in attention.php (shared with the attention banner).

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
    'sleep' => '😴',
    'mind' => '🧠',
    'bp' => '🩺',
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
// Same red/green shape as weight_pill — sleep_efficiency is Oura's own
// 0-100 score (written into daily_logs by oura_push.php/oura.php, not
// hand-entered), so no reason for the 3-tier consumption_pill scale here.
function sleep_pill($value, $href, $icon) {
    if ($value === null) return '<a class="pill pill-bad" href="' . htmlspecialchars($href) . '">' . $icon . '</a>';
    return '<a class="pill pill-good" href="' . htmlspecialchars($href) . '">' . $icon . ' ' . htmlspecialchars((int)$value) . '%</a>';
}
// Blood pressure pill (Fulgrim, feature list §1.2) — same "missing =
// pill-bad" convention as weight_pill/sleep_pill when there's no reading
// at all. When there IS at least one reading, color reflects the day's
// WORST category (not just the latest) so a concerning morning reading
// doesn't get visually buried by a better evening one; the number shown
// is the most recent reading, since that's what "today's BP" usually
// means in conversation.
function bp_pill($dayReadings, $href, $icon) {
    if (!$dayReadings) return '<a class="pill pill-bad" href="' . htmlspecialchars($href) . '">' . $icon . '</a>';
    $severityOrder = ['normal' => 0, 'elevated' => 1, 'stage1' => 2, 'stage2' => 3, 'crisis' => 4];
    $worst = 'normal';
    foreach ($dayReadings as $r) {
        $cat = bp_category($r['systolic'], $r['diastolic']);
        if ($cat && $severityOrder[$cat] > $severityOrder[$worst]) $worst = $cat;
    }
    $latest = $dayReadings[count($dayReadings) - 1];
    $cls = bp_category_pill_class($worst);
    return '<a class="pill ' . $cls . '" href="' . htmlspecialchars($href) . '">' . $icon . ' ' . (int)$latest['systolic'] . '/' . (int)$latest['diastolic'] . '</a>';
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

  <?php if ($attentionItems || $pendingEventCount): ?>
  <div id="attention" class="attention-banner">
    <h3 class="section-label">Attention needed</h3>
    <?php foreach ($attentionItems as $item): ?>
      <div class="attention-item">
        <a class="attention-item-label" href="<?= htmlspecialchars($item['href']) ?>"><?= htmlspecialchars($item['label']) ?></a>
        <?php if ($item['snoozable']): ?>
          <form method="post" action="index.php#attention" class="attention-snooze-form">
            <input type="hidden" name="snooze_key" value="<?= htmlspecialchars($item['key']) ?>">
            <button type="submit" class="btn-link attention-snooze-btn">Snooze until tomorrow</button>
          </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    <?php if ($pendingEventCount): ?>
      <div class="attention-item">
        <a class="attention-item-label" href="analysis.php"><?= $pendingEventCount ?> proposed correlation event<?= $pendingEventCount === 1 ? '' : 's' ?> awaiting your review →</a>
      </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <h3 class="section-label">Last 7 days</h3>
  <p class="hint" style="margin-top:-10px;"><a href="weight_trend.php">View weight trend (last 2 months) →</a> · <a href="blood_pressure_trend.php">View BP trend →</a></p>
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
        <?= bp_pill($bpByDay[$day] ?? [], daily_href($log, $day, 'section-bloodpressure'), $icons['bp']) ?>
        <?= sleep_pill($log ? $log['sleep_efficiency'] : null, daily_href($log, $day, 'section-sleep'), $icons['sleep']) ?>
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
