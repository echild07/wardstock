<?php
// Analysis sub-tab under Where When (Fulgrim, PLAN.md §11/§18) — one
// chart shell per analysis, grouped Automatic/Reported/Headline per §11's
// own framing. The actual Flux engine that computes these (§11's
// "Schedule & caching") isn't built yet — this page is real page
// structure/shells reading the real analysis_results table, showing "no
// data yet" until something pushes a row via api/analysis_push.php.
// Deliberately buildable and pushable now, independent of the Flux work
// (CLAUDE.md's GoDaddy-first build-order convention).
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/analysis_render.php';
require_login();

$pdo = get_db();
$active = 'wherewhen';
$subActive = 'analysis';

// Proposed-event confirm/deny (Fulgrim, feature list §3.2.2) — "hypothetical"
// correlated events the (not-yet-built) Flux engine will eventually write
// to proposed_events. Confirm creates a real incident from the suggestion
// and marks the proposal confirmed, so future analysis runs can weigh
// confirmed proposals as a confidence signal. Deny does NOT delete the
// row — Ward's own framing was "log it to be investigated," so a denied
// proposal stays in the table with status='denied' as that investigation
// record, just excluded from the pending queue.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['proposed_event_id'], $_POST['decision'])) {
    $peId = (int)$_POST['proposed_event_id'];
    $stmt = $pdo->prepare("SELECT * FROM proposed_events WHERE id = ? AND status = 'pending'");
    $stmt->execute([$peId]);
    $pe = $stmt->fetch();
    if ($pe) {
        if ($_POST['decision'] === 'confirm') {
            $ins = $pdo->prepare('INSERT INTO incidents (category, occurred_at, free_notes) VALUES (?, ?, ?)');
            $ins->execute([
                $pe['suggested_category'] ?: 'anxiety',
                $pe['suggested_occurred_at'] ?: $pe['proposed_at'],
                "Created from a wherewhen-proposed correlation event (confirmed): " . $pe['description'],
            ]);
            $newIncidentId = (int)$pdo->lastInsertId();
            $upd = $pdo->prepare("UPDATE proposed_events SET status = 'confirmed', reviewed_at = NOW(), created_incident_id = ? WHERE id = ?");
            $upd->execute([$newIncidentId, $peId]);
        } elseif ($_POST['decision'] === 'deny') {
            $upd = $pdo->prepare("UPDATE proposed_events SET status = 'denied', reviewed_at = NOW() WHERE id = ?");
            $upd->execute([$peId]);
        }
    }
    header('Location: analysis.php');
    exit;
}

$pendingEvents = $pdo->query("SELECT * FROM proposed_events WHERE status = 'pending' ORDER BY proposed_at DESC")->fetchAll();

// One row per analysis_key: latest computed_at, highest analysis_version.
// (Small table for now — a straightforward correlated subquery is plenty;
// revisit if this ever needs to scale past a few hundred rows.)
$latest = $pdo->query('
    SELECT r.* FROM analysis_results r
    INNER JOIN (
        SELECT analysis_key, MAX(analysis_version) AS max_version
        FROM analysis_results GROUP BY analysis_key
    ) v ON v.analysis_key = r.analysis_key AND v.max_version = r.analysis_version
    ORDER BY r.computed_at DESC
')->fetchAll();
$byKey = [];
foreach ($latest as $row) { $byKey[$row['analysis_key']] = $row; }

// The 20 analyses, PLAN.md §11 — group/number/title/description kept in
// sync with that section's own numbered list. Headline goes FIRST (Ward,
// Aug 2026: "the headline is the core functions" — make it the first
// thing people see), Automatic/Reported below it as the supporting detail.
$analyses = [
    'headline' => [
        ['key' => 'full_correlation_matrix', 'n' => 17, 'title' => 'Full correlation matrix', 'desc' => 'Incidents, alcohol, caffeine, sleep, weight, mood, HRV, exercise — the big picture.'],
        ['key' => 'unreported_anxiety_detection', 'n' => 18, 'title' => 'Unreported-anxiety detection', 'desc' => 'Physiological markers on days with no logged incident, pattern-matched against known incident days.'],
        ['key' => 'bedtime_wake_time_trend', 'n' => 19, 'title' => 'Bedtime / wake-time trend', 'desc' => 'When Ward actually went to bed and woke up — the clock times themselves, not duration.'],
        ['key' => 'sleep_stage_hypnogram', 'n' => 20, 'title' => 'Sleep-stage timeline (hypnogram)', 'desc' => 'Sleep stage in 15-minute increments, bounded to the earliest bedtime / latest wake time actually seen.'],
    ],
    'automatic' => [
        ['key' => 'sleep_duration_trend', 'n' => 1, 'title' => 'Sleep duration trend', 'desc' => 'Plain day-by-day time series.'],
        ['key' => 'sleep_efficiency_trend', 'n' => 2, 'title' => 'Sleep efficiency trend', 'desc' => 'Kept separate from duration — a full-but-fragmented night and a short-but-efficient one are different problems.'],
        ['key' => 'hrv_trend', 'n' => 3, 'title' => 'HRV trend', 'desc' => 'Watched for upward movement specifically — higher HRV generally reads as better recovery/lower stress.'],
        ['key' => 'resting_hr_trend', 'n' => 4, 'title' => 'Resting heart rate trend', 'desc' => 'The other core autonomic marker alongside HRV.'],
        ['key' => 'body_composition_trend', 'n' => 5, 'title' => 'Body composition trend', 'desc' => 'Weight plus body fat%, muscle mass, visceral fat%, etc. — extends the existing weight chart.'],
        ['key' => 'exercise_activity_trend', 'n' => 6, 'title' => 'Exercise / activity trend', 'desc' => 'Steps, exercise minutes, standing minutes.'],
        ['key' => 'weight_vs_medication_cadence', 'n' => 7, 'title' => 'Weight vs. medication cadence', 'desc' => 'Weight shifts around weekly/bi-weekly medication dosing.'],
        ['key' => 'medication_dosage_change_correlation', 'n' => 8, 'title' => 'Medication dosage-change correlation', 'desc' => 'Weight/HRV/sleep/mood/incidents before vs. after a dosage change.'],
        ['key' => 'medication_adherence_vs_incidents', 'n' => 9, 'title' => 'Medication adherence vs. incidents', 'desc' => 'Missed-dose days vs. incident occurrence/severity.'],
    ],
    'reported' => [
        ['key' => 'alcohol_caffeine_vs_sleep', 'n' => 10, 'title' => 'Alcohol & caffeine vs. sleep', 'desc' => 'Pairwise correlation.'],
        ['key' => 'mood_trend_correlation', 'n' => 11, 'title' => 'Mood / state-of-mind trend', 'desc' => 'Trend, plus correlation against sleep/alcohol/caffeine/incidents.'],
        ['key' => 'symptom_category_clustering', 'n' => 12, 'title' => 'Symptom & category clustering', 'desc' => 'Anxiety vs. cardiac vs. medical split over time, and symptom co-occurrence patterns.'],
        ['key' => 'incident_day_time_clustering', 'n' => 13, 'title' => 'Day-of-week / time-of-day incident clustering', 'desc' => 'Do incidents cluster on particular days or times.'],
        ['key' => 'incident_intensity_duration_trend', 'n' => 14, 'title' => 'Incident intensity/duration trend', 'desc' => 'The most direct "getting better or worse" view.'],
        ['key' => 'therapy_session_effects', 'n' => 15, 'title' => 'Therapy session effects', 'desc' => 'Mood before/after per session, and incident frequency around therapy cadence.'],
        ['key' => 'night_waking_context', 'n' => 16, 'title' => 'Night-waking context', 'desc' => 'Why Ward woke and what he was thinking, not just Oura\'s raw sleep-stage data.'],
    ],
];
$groupLabels = ['headline' => 'Headline — the core function of this page', 'automatic' => 'Automatic (Oura, scale, medication schedule)', 'reported' => 'Reported (incidents + Daily Log + therapy)'];
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>WardStock — Analysis</title>
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
      <h1>Analysis</h1>
    </div>
  </header>
  <?php include __DIR__ . '/partials_nav.php'; ?>
  <?php include __DIR__ . '/partials_wherewhen_nav.php'; ?>

  <p class="hint">Computed by <code>wherewhen</code> on the Home Assistant side and pushed here — nothing on this page runs locally. See <a href="status.php">Status</a> if a chart looks stale.</p>

  <?php if ($pendingEvents): ?>
    <h3 class="section-label">Proposed events awaiting your review</h3>
    <p class="hint">wherewhen thinks these might be real events based on a pattern it found. Confirm turns it into a real incident and helps wherewhen trust its own pattern-matching more next time; deny keeps it on record as something to look into, without creating an incident.</p>
    <div class="cards" style="margin-bottom: 28px;">
      <?php foreach ($pendingEvents as $pe): ?>
        <div class="card">
          <div class="card-top">
            <span class="card-date"><?= htmlspecialchars(date('M j, Y g:i A', strtotime($pe['proposed_at']))) ?></span>
            <span class="tag"><?= htmlspecialchars($pe['analysis_key']) ?></span>
          </div>
          <p class="card-trigger"><?= htmlspecialchars($pe['description']) ?></p>
          <?php if ($pe['confidence'] !== null): ?>
            <p class="hint">Confidence: <?= htmlspecialchars((string)round($pe['confidence'] * 100)) ?>%</p>
          <?php endif; ?>
          <div style="display:flex; gap:10px; margin-top:8px;">
            <form method="post" action="analysis.php">
              <input type="hidden" name="proposed_event_id" value="<?= (int)$pe['id'] ?>">
              <input type="hidden" name="decision" value="confirm">
              <button type="submit" class="btn btn-sm">Confirm — make it an incident</button>
            </form>
            <form method="post" action="analysis.php">
              <input type="hidden" name="proposed_event_id" value="<?= (int)$pe['id'] ?>">
              <input type="hidden" name="decision" value="deny">
              <button type="submit" class="btn btn-sm btn-danger">Deny</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php foreach ($analyses as $group => $items): ?>
    <h3 class="section-label"><?= htmlspecialchars($groupLabels[$group]) ?></h3>
    <div class="analysis-grid">
      <?php foreach ($items as $a): $result = $byKey[$a['key']] ?? null; ?>
        <div class="analysis-card">
          <div class="analysis-card-top">
            <span class="badge">#<?= $a['n'] ?></span>
            <?php if ($result): ?>
              <span class="tag lvl-mild">Last computed <?= htmlspecialchars(date('M j, g:i A', strtotime($result['computed_at']))) ?></span>
            <?php else: ?>
              <span class="tag">No data yet</span>
            <?php endif; ?>
          </div>
          <h2><?= htmlspecialchars($a['title']) ?></h2>
          <p><?= htmlspecialchars($a['desc']) ?></p>
          <?php
            $decoded = $result ? json_decode($result['result_json'], true) : null;
            echo render_analysis_result($a['key'], $decoded);
          ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
</div>
</body>
</html>
