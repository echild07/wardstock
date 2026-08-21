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

// One row per (analysis_key, period_type) — the database itself now
// guarantees this (analysis_results' UNIQUE KEY, schema.sql), so no
// dedup query is needed here at all; this table is bounded at roughly
// (# analyses) x 4 tiers, small enough to just fetch everything.
//
// Which tier to SHOW, per analysis_key, is a real design question Ward
// raised directly (Aug 2026): "if daily/weekly/monthly and manual all
// are run, won't the last one be shown, so if daily was run last, it
// will only show a day with no way to get monthly?" — yes, exactly, and
// that was true even before today's dedup bug fix, since daily runs far
// more often than monthly/weekly/all and would almost always be the
// most-recently-computed row. Picking by recency was never right here.
//
// Two-part answer: (1) a page-wide period selector (?period=) lets Ward
// explicitly choose a tier — "the user will need to be able to select
// the period" — rather than the page silently deciding for him; (2) the
// default (?period= absent) auto-picks the WIDEST available window
// regardless of which ran most recently (all=since Aug 2025 >
// monthly=400d > weekly=180d > daily=90d), falling back to whatever
// tiers actually have data yet, so a fresh install showing only "daily"
// still works fine until the wider tiers get their first scheduled run.
// If a SPECIFIC tier is requested but a given analysis_key has no data
// for it yet, that card just shows "No data yet" for this key rather
// than silently substituting a different tier — an explicit choice
// should show exactly what was asked for, not something else.
$validPeriods = ['all', 'monthly', 'weekly', 'daily'];
$selectedPeriod = in_array($_GET['period'] ?? '', $validPeriods, true) ? $_GET['period'] : null;
$tierPreference = ['all' => 0, 'monthly' => 1, 'weekly' => 2, 'daily' => 3]; // lower = shown preferentially
$allResults = $pdo->query('SELECT * FROM analysis_results')->fetchAll();
$byKey = [];
foreach ($allResults as $row) {
    $key = $row['analysis_key'];
    if ($selectedPeriod !== null) {
        if ($row['period_type'] === $selectedPeriod) $byKey[$key] = $row;
        continue;
    }
    $rank = $tierPreference[$row['period_type']] ?? 99;
    if (!isset($byKey[$key]) || $rank < ($tierPreference[$byKey[$key]['period_type']] ?? 99)) {
        $byKey[$key] = $row;
    }
}

// Raw-data export for debugging a specific chart (Ward, Aug 2026 —
// requested on the bedtime/wake-time trend and sleep-stage hypnogram
// after both showed a timezone display bug; built generically, and now
// surfaced as a visible "Export" link on every analysis card, not just
// those original two — see the card loop below).
if (isset($_GET['export']) && isset($byKey[$_GET['export']])) {
    $exportKey = $_GET['export'];
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-z0-9_]/', '', $exportKey) . '_' . date('Y-m-d_His') . '.json"');
    echo $byKey[$exportKey]['result_json'];
    exit;
}

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
      <img src="wherewhen-logo.png" alt="" width="36" height="36" class="brand-mark">
      <h1>Analysis</h1>
    </div>
  </header>
  <?php include __DIR__ . '/partials_nav.php'; ?>
  <?php include __DIR__ . '/partials_wherewhen_nav.php'; ?>

  <p class="hint">Computed by <code>wherewhen</code> on the Home Assistant side and pushed here — nothing on this page runs locally. See <a href="status.php">Status</a> if a chart looks stale.</p>

  <?php
    // Period selector (Ward, Aug 2026 — "the user will need to be able
    // to select the period"). Plain links with a query param, not a
    // form/JS toggle — simplest thing that reloads the whole page
    // showing every card at the chosen tier, and works identically
    // whether JS is enabled or not.
    $periodLabels = ['daily' => 'Daily (90d)', 'weekly' => 'Weekly (180d)', 'monthly' => 'Monthly (400d)', 'all' => 'All-data'];
  ?>
  <div class="period-selector">
    <span class="hint">Period:</span>
    <a class="period-chip<?= $selectedPeriod === null ? ' active' : '' ?>" href="analysis.php">Auto (widest available)</a>
    <?php foreach ($periodLabels as $p => $label): ?>
      <a class="period-chip<?= $selectedPeriod === $p ? ' active' : '' ?>" href="analysis.php?period=<?= urlencode($p) ?>"><?= htmlspecialchars($label) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if ($pendingEvents): ?>
    <h3 class="section-label">Proposed events awaiting your review</h3>
    <p class="hint">wherewhen thinks these might be real events based on a pattern it found. Confirm turns it into a real incident and helps wherewhen trust its own pattern-matching more next time; deny keeps it on record as something to look into, without creating an incident.</p>
    <div class="cards" style="margin-bottom: 28px;">
      <?php foreach ($pendingEvents as $pe): ?>
        <div class="card">
          <div class="card-top">
            <span class="card-date"><code class="js-local-time" data-utc="<?= htmlspecialchars(str_replace(' ', 'T', $pe['proposed_at']) . 'Z') ?>"><?= htmlspecialchars($pe['proposed_at']) ?> UTC</code></span>
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
              <span class="tag lvl-mild"><?= htmlspecialchars(ucfirst($result['period_type'])) ?> · computed <code class="js-local-time" data-utc="<?= htmlspecialchars(str_replace(' ', 'T', $result['computed_at']) . 'Z') ?>"><?= htmlspecialchars($result['computed_at']) ?> UTC</code></span>
            <?php elseif ($selectedPeriod !== null): ?>
              <span class="tag">No <?= htmlspecialchars($selectedPeriod) ?> data yet — try Auto or another period</span>
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
          <?php if ($result): ?>
            <p class="hint"><a href="analysis.php?export=<?= urlencode($a['key']) ?>">Export raw data (JSON) →</a></p>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
</div>
<script>
// Builds the bedtime/wake-time trend and sleep-stage hypnogram charts
// client-side, from raw UTC timestamps the server sends as-is (see
// analysis_render.php's comments on those two cases in
// render_analysis_result()). Ward, Aug 2026: a server-side Eastern
// conversion was tried first and was the wrong call — whoever is
// actually looking at the page is the one whose timezone matters, and
// the browser already knows that. JS's own Date object resolves a UTC
// ISO string to the browser's local time for free via getHours()/
// getMinutes() — no timezone library, no guessing.
(function () {
    function pad2(n) { return String(n).padStart(2, '0'); }

    function hoursSinceNoonLocal(iso) {
        var d = new Date(iso);
        var raw = d.getHours() + d.getMinutes() / 60;
        return ((raw - 12) + 24) % 24;
    }

    function fmtClockFromNoonOffset(h) {
        if (h === null || h === undefined || isNaN(h)) return '—';
        var actual = ((h + 12) % 24 + 24) % 24;
        var hh = Math.floor(actual);
        var mm = Math.round((actual - hh) * 60);
        if (mm === 60) { hh = (hh + 1) % 24; mm = 0; }
        var period = hh < 12 ? 'AM' : 'PM';
        var hh12 = hh % 12; if (hh12 === 0) hh12 = 12;
        return hh12 + ':' + pad2(mm) + ' ' + period;
    }

    function fmtDayLocal(iso) {
        var d = new Date(iso);
        return d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
    }

    function fmtClockLocal(iso) {
        var d = new Date(iso);
        var hh = d.getHours(), mm = d.getMinutes();
        var period = hh < 12 ? 'AM' : 'PM';
        var hh12 = hh % 12; if (hh12 === 0) hh12 = 12;
        return hh12 + ':' + pad2(mm) + ' ' + period;
    }

    function svgEl(tag, attrs) {
        var el = document.createElementNS('http://www.w3.org/2000/svg', tag);
        for (var k in attrs) el.setAttribute(k, attrs[k]);
        return el;
    }

    function titled(el, text) {
        var t = svgEl('title', {});
        t.textContent = text;
        el.appendChild(t);
        return el;
    }

    function renderBedtimeChart(container) {
        var raw = [];
        try { raw = JSON.parse(container.dataset.points || '[]'); } catch (e) { raw = []; }
        var statsEl = container.parentElement.querySelector('.js-bedtime-stats');

        if (!raw.length) {
            container.innerHTML = container.dataset.oldFormat === '1'
                ? '<p class="hint">Stored data for this analysis is in an older format — re-run the Analysis Engine flow to refresh it.</p>'
                : '<p class="hint">Not enough nights in this period yet.</p>';
            if (statsEl) statsEl.innerHTML = '';
            return;
        }

        var points = raw.map(function (p) {
            return {
                date: p.bedtime_time,
                bedtime_offset: hoursSinceNoonLocal(p.bedtime_time),
                wake_offset: hoursSinceNoonLocal(p.wake_time),
            };
        });

        var allV = [];
        points.forEach(function (p) { allV.push(p.bedtime_offset, p.wake_offset); });
        var minV = Math.min.apply(null, allV), maxV = Math.max.apply(null, allV);
        var pad = Math.max(0.5, (maxV - minV) * 0.15);
        minV -= pad; maxV += pad;
        if (maxV === minV) { maxV += 1; minV -= 1; }

        var firstT = new Date(points[0].date).getTime();
        var lastT = new Date(points[points.length - 1].date).getTime();
        var span = Math.max(1, lastT - firstT);

        var chartW = 640, chartH = 240, padL = 60, padR = 16, padT = 16, padB = 30;
        var plotW = chartW - padL - padR, plotH = chartH - padT - padB;
        var baseY = chartH - padB;
        var barW = points.length > 1 ? Math.max(3, Math.min(14, plotW / points.length * 0.6)) : 14;
        function yFor(v) { return padT + plotH * (1 - (v - minV) / (maxV - minV)); }

        var svg = svgEl('svg', { viewBox: '0 0 ' + chartW + ' ' + chartH, class: 'trend-chart', xmlns: 'http://www.w3.org/2000/svg' });
        svg.appendChild(svgEl('line', { x1: padL, y1: padT, x2: padL, y2: baseY, class: 'chart-axis' }));
        svg.appendChild(svgEl('line', { x1: padL, y1: baseY, x2: chartW - padR, y2: baseY, class: 'chart-axis' }));

        var maxLabel = svgEl('text', { x: padL - 6, y: padT + 4, class: 'chart-label', 'text-anchor': 'end' });
        maxLabel.textContent = fmtClockFromNoonOffset(maxV);
        svg.appendChild(maxLabel);
        var minLabel = svgEl('text', { x: padL - 6, y: baseY + 4, class: 'chart-label', 'text-anchor': 'end' });
        minLabel.textContent = fmtClockFromNoonOffset(minV);
        svg.appendChild(minLabel);
        var firstLabel = svgEl('text', { x: padL, y: baseY + 18, class: 'chart-label' });
        firstLabel.textContent = fmtDayLocal(points[0].date);
        svg.appendChild(firstLabel);
        var lastLabel = svgEl('text', { x: chartW - padR, y: baseY + 18, class: 'chart-label', 'text-anchor': 'end' });
        lastLabel.textContent = fmtDayLocal(points[points.length - 1].date);
        svg.appendChild(lastLabel);

        points.forEach(function (p) {
            var t = new Date(p.date).getTime();
            var x = padL + plotW * ((t - firstT) / span);
            var yA = yFor(p.bedtime_offset), yB = yFor(p.wake_offset);
            var yTop = Math.min(yA, yB), yBottom = Math.max(yA, yB);
            var dayLabel = fmtDayLocal(p.date);

            var rect = svgEl('rect', { x: x - barW / 2, y: yTop, width: barW, height: Math.max(1, yBottom - yTop), class: 'av-range-bar' });
            titled(rect, dayLabel + ': ' + fmtClockFromNoonOffset(p.bedtime_offset) + ' — ' + fmtClockFromNoonOffset(p.wake_offset));
            svg.appendChild(rect);

            var cA = svgEl('circle', { cx: x, cy: yA, r: 3, class: 'chart-point' });
            titled(cA, dayLabel + ' — ' + fmtClockFromNoonOffset(p.bedtime_offset));
            svg.appendChild(cA);

            var cB = svgEl('circle', { cx: x, cy: yB, r: 3, class: 'chart-point-diastolic' });
            titled(cB, dayLabel + ' — ' + fmtClockFromNoonOffset(p.wake_offset));
            svg.appendChild(cB);
        });

        container.innerHTML = '';
        container.appendChild(svg);

        if (statsEl) {
            var avgBedtime = points.reduce(function (s, p) { return s + p.bedtime_offset; }, 0) / points.length;
            var avgWake = points.reduce(function (s, p) { return s + p.wake_offset; }, 0) / points.length;
            statsEl.innerHTML =
                '<div class="report-stat"><span class="report-num">' + fmtClockFromNoonOffset(avgBedtime) + '</span><span class="report-label">Avg bedtime</span></div>' +
                '<div class="report-stat"><span class="report-num">' + fmtClockFromNoonOffset(avgWake) + '</span><span class="report-label">Avg wake time</span></div>';
        }
    }

    function renderHypnogram(container) {
        var buckets = [];
        try { buckets = JSON.parse(container.dataset.buckets || '[]'); } catch (e) { buckets = []; }
        if (!buckets.length) return; // PHP already rendered a "no data" message in this case.

        var stageColor = { deep: '#3f5f9a', light: '#5b8cff', rem: '#9a7fdb', awake: '#a13f3f', unknown: '#3a4048' };
        var stageLabel = { deep: 'Deep', light: 'Light', rem: 'REM', awake: 'Awake', unknown: 'Unknown' };
        var n = buckets.length;
        var chartW = 640, chartH = 70, segW = chartW / Math.max(1, n);

        var svg = svgEl('svg', { viewBox: '0 0 ' + chartW + ' ' + chartH, class: 'trend-chart', xmlns: 'http://www.w3.org/2000/svg' });
        buckets.forEach(function (b, i) {
            var stage = b.stage || 'unknown';
            var color = stageColor[stage] || stageColor.unknown;
            var rect = svgEl('rect', { x: i * segW, y: 10, width: Math.ceil(segW), height: 30, fill: color });
            titled(rect, fmtClockLocal(b.time) + ': ' + (stageLabel[stage] || stage));
            svg.appendChild(rect);
        });
        var firstLabel = svgEl('text', { x: 0, y: 58, class: 'chart-label' });
        firstLabel.textContent = fmtClockLocal(buckets[0].time);
        svg.appendChild(firstLabel);
        var lastLabel = svgEl('text', { x: chartW, y: 58, class: 'chart-label', 'text-anchor': 'end' });
        lastLabel.textContent = fmtClockLocal(buckets[n - 1].time);
        svg.appendChild(lastLabel);

        container.innerHTML = '';
        container.appendChild(svg);
    }

    document.querySelectorAll('.js-bedtime-chart').forEach(renderBedtimeChart);
    document.querySelectorAll('.js-hypnogram-chart').forEach(renderHypnogram);

    // "Last computed" timestamps, converted to the browser's own local
    // timezone (Ward, Aug 2026 — same raw-UTC gap as the Status page's
    // last_run_at, same fix: data-utc is an explicit UTC ISO string, and
    // this replaces the UTC-labeled fallback text with a local-time
    // version once JS runs).
    document.querySelectorAll('.js-local-time').forEach(function (el) {
        var d = new Date(el.dataset.utc);
        if (isNaN(d.getTime())) return;
        el.textContent = d.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
    });

    // Custom day/value tooltip for every chart (Ward, Aug 2026 — "I would
    // like to see the day and value if I hover over it... used on a
    // mobile device"). Runs after the two render calls above so it also
    // picks up the bedtime/hypnogram charts' own <title> elements, not
    // just the server-rendered trend-chart bars (av_trend_svg() in
    // analysis_render.php already puts a <title> on every bar/point) —
    // this replaces the browser's native SVG title tooltip rather than
    // duplicating it: native tooltips are desktop-hover-only, slow to
    // appear, and have poor/inconsistent support on touch devices.
    var tip = document.createElement('div');
    tip.className = 'av-tooltip';
    tip.style.display = 'none';
    document.body.appendChild(tip);

    function showTip(x, y, text) {
        tip.textContent = text;
        tip.style.display = 'block';
        var pad = 14;
        var left = x + pad;
        var maxLeft = window.innerWidth - tip.offsetWidth - 8;
        if (left > maxLeft) left = x - tip.offsetWidth - pad; // flip to the left edge rather than run off-screen
        tip.style.left = Math.max(8, left) + 'px';
        tip.style.top = (y + pad) + 'px';
    }
    function hideTip() { tip.style.display = 'none'; }

    var activeEl = null;
    document.querySelectorAll('svg.trend-chart title').forEach(function (titleEl) {
        var target = titleEl.parentNode;
        var text = titleEl.textContent;
        titleEl.remove(); // drop the native title so it can't also pop up underneath this one

        target.addEventListener('pointerenter', function (e) {
            if (e.pointerType === 'touch') return; // touch uses tap (pointerdown), below, instead of hover
            showTip(e.clientX, e.clientY, text);
        });
        target.addEventListener('pointermove', function (e) {
            if (e.pointerType === 'touch') return;
            showTip(e.clientX, e.clientY, text);
        });
        target.addEventListener('pointerleave', function (e) {
            if (e.pointerType === 'touch') return;
            hideTip();
        });
        target.addEventListener('pointerdown', function (e) {
            if (e.pointerType !== 'touch') return;
            e.stopPropagation(); // before the document-level listener below closes it right back up
            if (activeEl === target) {
                hideTip();
                activeEl = null;
            } else {
                showTip(e.clientX, e.clientY, text);
                activeEl = target;
            }
        });
    });
    // Tapping anywhere else on a touch device dismisses whatever's open —
    // there's no hover state to fall back on to close it automatically.
    document.addEventListener('pointerdown', function (e) {
        if (e.pointerType === 'touch' && activeEl) {
            hideTip();
            activeEl = null;
        }
    });
})();
</script>
</body>
</html>
