<?php
// Chart/table rendering for analysis.php's ~20 analyses (Fulgrim, PLAN.md
// §11's Flux engine — homeassistant/nodered/analysis_engine_flow.json).
// The engine pushes each analysis's own result shape (see that flow's
// embedded ANALYSES registry — ~20 distinct shapes: single trend series,
// dual trend series, correlation coefficients, a pairwise matrix,
// clustering counts, event lists, a hypnogram). Rather than one bespoke
// renderer per analysis, this file has a handful of reusable primitives
// (trend chart, bar list, stat row, table, correlation line, hypnogram)
// and render_analysis_result() dispatches each of the 20 keys to the
// right combination — mirrors the Flux engine's own "shared helpers +
// per-key registry" shape, same reasoning: one place to look, not 20
// near-duplicate functions.

function fmt_num($v, $decimals = 1) {
    if ($v === null) return '—';
    return number_format((float)$v, $decimals);
}

// Qualitative label for a Pearson coefficient — descriptive convention
// only (Cohen's commonly-cited thresholds), not a claim of significance.
// Same "not medical advice" framing this whole feature list carries.
function av_corr_strength_label($r) {
    if ($r === null) return 'not enough paired data';
    $abs = abs((float)$r);
    if ($abs < 0.2) return 'negligible';
    if ($abs < 0.4) return 'weak';
    if ($abs < 0.6) return 'moderate';
    if ($abs < 0.8) return 'strong';
    return 'very strong';
}

// Generic single-series SVG trend chart — bars, not a connected line
// (Ward, Aug 2026: "bar charts would be better, the line charts don't
// add anything... at least the lines between them don't" — a line
// implies continuity/interpolation between days that isn't really there
// for once-a-day values, especially with real gaps in the data). Same
// coordinate/min-max-with-padding computation as before (weight_trend.php/
// blood_pressure_trend.php's pattern), generalized to any {date, value}
// series. $date may be 'YYYY-MM-DD' or a full ISO timestamp —
// strtotime() handles both, so callers never need to care which shape a
// given analysis produced.
function av_trend_svg($series, $decimals = 1) {
    // Bars (unlike the line this used to be) are meaningful with even a
    // single point, so the floor here is 1, not 2.
    if (count($series) < 1) return null;
    $values = array_map(fn($p) => (float)$p['value'], $series);
    $minV = min($values); $maxV = max($values);
    $pad = max(0.5, ($maxV - $minV) * 0.15);
    $minV -= $pad; $maxV += $pad;
    if ($maxV == $minV) { $maxV += 1; $minV -= 1; }

    $firstT = strtotime($series[0]['date']);
    $lastT = strtotime($series[count($series) - 1]['date']);
    $span = max(1, $lastT - $firstT);

    $chartW = 640; $chartH = 220;
    $padL = 50; $padR = 16; $padT = 16; $padB = 30;
    $plotW = $chartW - $padL - $padR;
    $plotH = $chartH - $padT - $padB;
    $baseY = $chartH - $padB;
    $barW = count($series) > 1 ? max(3, min(16, $plotW / count($series) * 0.6)) : 16;

    $bars = [];
    foreach ($series as $p) {
        $t = strtotime($p['date']);
        $x = round($padL + $plotW * (($t - $firstT) / $span), 1);
        $y = round($padT + $plotH * (1 - (((float)$p['value']) - $minV) / ($maxV - $minV)), 1);
        $bars[] = [$x, $y, $p];
    }

    ob_start();
    ?>
    <svg viewBox="0 0 <?= $chartW ?> <?= $chartH ?>" class="trend-chart" xmlns="http://www.w3.org/2000/svg">
      <line x1="<?= $padL ?>" y1="<?= $padT ?>" x2="<?= $padL ?>" y2="<?= $baseY ?>" class="chart-axis" />
      <line x1="<?= $padL ?>" y1="<?= $baseY ?>" x2="<?= $chartW - $padR ?>" y2="<?= $baseY ?>" class="chart-axis" />
      <text x="<?= $padL - 6 ?>" y="<?= $padT + 4 ?>" class="chart-label" text-anchor="end"><?= fmt_num($maxV, $decimals) ?></text>
      <text x="<?= $padL - 6 ?>" y="<?= $baseY + 4 ?>" class="chart-label" text-anchor="end"><?= fmt_num($minV, $decimals) ?></text>
      <text x="<?= $padL ?>" y="<?= $baseY + 18 ?>" class="chart-label"><?= htmlspecialchars(date('M j', $firstT)) ?></text>
      <text x="<?= $chartW - $padR ?>" y="<?= $baseY + 18 ?>" class="chart-label" text-anchor="end"><?= htmlspecialchars(date('M j', $lastT)) ?></text>
      <?php foreach ($bars as $c): ?>
        <rect x="<?= round($c[0] - $barW / 2, 1) ?>" y="<?= $c[1] ?>" width="<?= round($barW, 1) ?>" height="<?= max(1, round($baseY - $c[1], 1)) ?>" class="av-range-bar">
          <title><?= htmlspecialchars(date('M j, Y', strtotime($c[2]['date']))) ?>: <?= fmt_num($c[2]['value'], $decimals) ?></title>
        </rect>
      <?php endforeach; ?>
    </svg>
    <?php
    return ob_get_clean();
}

// Converts an hours-since-noon offset (the Flux engine's storage shape
// for bedtime/wake, chosen specifically to avoid the midnight-wraparound
// a raw 0-24 clock hour would hit — see analysis_engine_flow.json's own
// comment on this) back into a real clock-time label for display.
function fmt_clock_from_noon_offset($h) {
    if ($h === null) return '—';
    $actualHour = fmod($h + 12, 24);
    if ($actualHour < 0) $actualHour += 24;
    $hh = (int)floor($actualHour);
    $mm = (int)round(($actualHour - $hh) * 60);
    if ($mm == 60) { $hh = ($hh + 1) % 24; $mm = 0; }
    $period = $hh < 12 ? 'AM' : 'PM';
    $hh12 = $hh % 12; if ($hh12 == 0) $hh12 = 12;
    return sprintf('%d:%02d %s', $hh12, $mm, $period);
}

// Range/floating-bar chart — one vertical bar per day, spanning from
// $keyA's value to $keyB's value, rather than two separate polylines.
// Built specifically for bedtime/wake-time trend (Ward, Aug 2026 —
// "the two line graphs don't look good... a better chart to show the
// start and end time as if they were connected together"): each night's
// bedtime and wake time are one connected span, not two independent
// trends that happen to share an x-axis. Y-axis values are still plain
// numbers internally (hours-since-noon for this analysis) but labeled
// with $labelFn if given, so the axis reads as real clock times instead
// of a raw offset number.
function av_range_bar_svg($series, $keyA, $keyB, $labelFn = null) {
    if (count($series) < 1) return null;
    $fmt = $labelFn ?: fn($v) => fmt_num($v, 1);
    $allValues = array_merge(array_map(fn($p) => (float)$p[$keyA], $series), array_map(fn($p) => (float)$p[$keyB], $series));
    $minV = min($allValues); $maxV = max($allValues);
    $pad = max(0.5, ($maxV - $minV) * 0.15);
    $minV -= $pad; $maxV += $pad;
    if ($maxV == $minV) { $maxV += 1; $minV -= 1; }

    $firstT = strtotime($series[0]['date']);
    $lastT = strtotime($series[count($series) - 1]['date']);
    $span = max(1, $lastT - $firstT);

    $chartW = 640; $chartH = 240;
    $padL = 60; $padR = 16; $padT = 16; $padB = 30;
    $plotW = $chartW - $padL - $padR;
    $plotH = $chartH - $padT - $padB;
    // Bars need real screen width, not a hairline — same idea as a
    // candlestick chart. Scale down as more days are packed in.
    $barW = count($series) > 1 ? max(3, min(14, $plotW / count($series) * 0.6)) : 14;

    // A closure, not a named function — a named `function yFor(...)` here
    // would be declared in PHP's global scope the first time this runs
    // and fatal-error with "Cannot redeclare" on a second call in the
    // same request (this function is only called once per page today,
    // but that's a fragile thing to rely on).
    $yFor = fn($v) => $padT + $plotH * (1 - ($v - $minV) / ($maxV - $minV));

    ob_start();
    ?>
    <svg viewBox="0 0 <?= $chartW ?> <?= $chartH ?>" class="trend-chart" xmlns="http://www.w3.org/2000/svg">
      <line x1="<?= $padL ?>" y1="<?= $padT ?>" x2="<?= $padL ?>" y2="<?= $chartH - $padB ?>" class="chart-axis" />
      <line x1="<?= $padL ?>" y1="<?= $chartH - $padB ?>" x2="<?= $chartW - $padR ?>" y2="<?= $chartH - $padB ?>" class="chart-axis" />
      <text x="<?= $padL - 6 ?>" y="<?= $padT + 4 ?>" class="chart-label" text-anchor="end"><?= htmlspecialchars($fmt($maxV)) ?></text>
      <text x="<?= $padL - 6 ?>" y="<?= $chartH - $padB + 4 ?>" class="chart-label" text-anchor="end"><?= htmlspecialchars($fmt($minV)) ?></text>
      <text x="<?= $padL ?>" y="<?= $chartH - $padB + 18 ?>" class="chart-label"><?= htmlspecialchars(date('M j', $firstT)) ?></text>
      <text x="<?= $chartW - $padR ?>" y="<?= $chartH - $padB + 18 ?>" class="chart-label" text-anchor="end"><?= htmlspecialchars(date('M j', $lastT)) ?></text>
      <?php foreach ($series as $p):
          $t = strtotime($p['date']);
          $x = round($padL + $plotW * (($t - $firstT) / $span), 1);
          $yA = round($yFor((float)$p[$keyA]), 1);
          $yB = round($yFor((float)$p[$keyB]), 1);
          $yTop = min($yA, $yB); $yBottom = max($yA, $yB);
          $dayLabel = htmlspecialchars(date('M j', $t));
      ?>
        <rect x="<?= round($x - $barW / 2, 1) ?>" y="<?= $yTop ?>" width="<?= round($barW, 1) ?>" height="<?= max(1, round($yBottom - $yTop, 1)) ?>" class="av-range-bar">
          <title><?= $dayLabel ?>: <?= htmlspecialchars($fmt($p[$keyA])) ?> — <?= htmlspecialchars($fmt($p[$keyB])) ?></title>
        </rect>
        <circle cx="<?= $x ?>" cy="<?= $yA ?>" r="3" class="chart-point"><title><?= $dayLabel ?> — <?= htmlspecialchars($fmt($p[$keyA])) ?></title></circle>
        <circle cx="<?= $x ?>" cy="<?= $yB ?>" r="3" class="chart-point-diastolic"><title><?= $dayLabel ?> — <?= htmlspecialchars($fmt($p[$keyB])) ?></title></circle>
      <?php endforeach; ?>
    </svg>
    <?php
    return ob_get_clean();
}

// Full trendResult block (chart + stat row) — the shape every single-
// series trend analysis (#1-4, 14, and each sub-series of #5/6/11)
// returns from the engine's own trendResult() helper.
function av_trend_block($trend, $unit = '', $decimals = 1) {
    if (!$trend || empty($trend['series'])) {
        return '<p class="hint">No data in this result yet.</p>';
    }
    // av_trend_svg() only returns null on a truly empty series, already
    // ruled out above — bars, unlike the line chart this used to be, are
    // meaningful even for a single point, so there's no smaller-but-
    // nonzero case left to handle here.
    $svg = av_trend_svg($trend['series'], $decimals);
    $slope = $trend['trend_slope_per_day'] ?? null;
    $slopeLabel = $slope === null ? 'flat / not enough data' : (($slope > 0.001 ? '↑ rising' : ($slope < -0.001 ? '↓ falling' : '→ flat')) . ' (' . fmt_num($slope, 3) . '/day)');
    $html = '<div class="report-box">' . $svg . '</div>';
    $html .= '<div class="report-stats">';
    $html .= '<div class="report-stat"><span class="report-num">' . fmt_num($trend['latest']['value'] ?? null, $decimals) . htmlspecialchars($unit) . '</span><span class="report-label">Latest</span></div>';
    $html .= '<div class="report-stat"><span class="report-num">' . fmt_num($trend['average'] ?? null, $decimals) . htmlspecialchars($unit) . '</span><span class="report-label">Average</span></div>';
    $html .= '<div class="report-stat"><span class="report-num">' . htmlspecialchars($slopeLabel) . '</span><span class="report-label">Trend</span></div>';
    $html .= '<div class="report-stat"><span class="report-num">' . (int)($trend['count'] ?? count($trend['series'])) . '</span><span class="report-label">Points</span></div>';
    $html .= '</div>';
    return $html;
}

// Correlation block — {label, paired_days, correlation, points}.
function av_correlation_block($corr) {
    if (!$corr) return '<p class="hint">No data yet.</p>';
    $r = $corr['correlation'] ?? null;
    $strength = av_corr_strength_label($r);
    $sign = $r !== null ? ($r > 0 ? 'positive' : ($r < 0 ? 'negative' : 'no')) : '';
    $rLabel = $r === null ? 'not enough paired data' : fmt_num($r, 2) . ' (' . $strength . ' ' . $sign . ')';
    return '<p><strong>' . htmlspecialchars($corr['label'] ?? '') . ':</strong> ' . htmlspecialchars($rLabel) . '</p>' .
        '<p class="hint">Based on ' . (int)($corr['paired_days'] ?? 0) . ' day(s) with both values present.</p>';
}

// Horizontal bar list — $items: [[label, count], ...], already sorted by
// caller if a particular order matters.
function av_bar_list($items) {
    if (!$items) return '<p class="hint">No data yet.</p>';
    $max = max(array_map(fn($i) => (float)$i[1], $items)) ?: 1;
    $html = '<div class="av-bar-list">';
    foreach ($items as [$label, $count]) {
        $pct = $max > 0 ? round(($count / $max) * 100) : 0;
        $html .= '<div class="av-bar-row"><span class="av-bar-label">' . htmlspecialchars($label) . '</span>' .
            '<span class="av-bar-track"><span class="av-bar-fill" style="width:' . $pct . '%"></span></span>' .
            '<span class="av-bar-count">' . htmlspecialchars((string)$count) . '</span></div>';
    }
    return $html . '</div>';
}

// Small stat tiles — $items: [[label, value], ...].
function av_stat_row($items) {
    $html = '<div class="report-stats">';
    foreach ($items as [$label, $value]) {
        $html .= '<div class="report-stat"><span class="report-num">' . htmlspecialchars((string)$value) . '</span><span class="report-label">' . htmlspecialchars($label) . '</span></div>';
    }
    return $html . '</div>';
}

// Generic table — $headers: [string], $rows: [[cell, cell, ...], ...] (cells pre-formatted, already htmlspecialchars'd by caller where needed).
function av_table($headers, $rows) {
    if (!$rows) return '<p class="hint">No data yet.</p>';
    $html = '<table class="day-table"><thead><tr>';
    foreach ($headers as $h) $html .= '<th>' . htmlspecialchars($h) . '</th>';
    $html .= '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $html .= '<tr>';
        foreach ($row as $cell) $html .= '<td>' . $cell . '</td>';
        $html .= '</tr>';
    }
    return $html . '</tbody></table>';
}

// Hypnogram — a horizontal timeline of colored segments, one per 15-min
// bucket. Not an SVG line chart (nothing to plot on a y-axis — stage is
// categorical) so this is its own small renderer.
function av_hypnogram_svg($buckets) {
    if (!$buckets) return null;
    $stageColor = ['deep' => '#3f5f9a', 'light' => '#5b8cff', 'rem' => '#9a7fdb', 'awake' => '#a13f3f', 'unknown' => '#3a4048'];
    $stageLabel = ['deep' => 'Deep', 'light' => 'Light', 'rem' => 'REM', 'awake' => 'Awake', 'unknown' => 'Unknown'];
    $n = count($buckets);
    $chartW = 640; $chartH = 70; $segW = $chartW / max(1, $n);
    ob_start();
    ?>
    <svg viewBox="0 0 <?= $chartW ?> <?= $chartH ?>" class="trend-chart" xmlns="http://www.w3.org/2000/svg">
      <?php foreach ($buckets as $i => $b):
          $stage = $b['stage'] ?? 'unknown';
          $color = $stageColor[$stage] ?? $stageColor['unknown'];
      ?>
        <rect x="<?= round($i * $segW, 1) ?>" y="10" width="<?= ceil($segW) ?>" height="30" fill="<?= $color ?>">
          <title><?= htmlspecialchars(date('g:i A', strtotime($b['time']))) ?>: <?= htmlspecialchars($stageLabel[$stage] ?? $stage) ?></title>
        </rect>
      <?php endforeach; ?>
      <text x="0" y="58" class="chart-label"><?= htmlspecialchars(date('g:i A', strtotime($buckets[0]['time']))) ?></text>
      <text x="<?= $chartW ?>" y="58" class="chart-label" text-anchor="end"><?= htmlspecialchars(date('g:i A', strtotime($buckets[$n - 1]['time']))) ?></text>
    </svg>
    <p class="hint">
      <?php foreach ($stageColor as $stage => $color): if ($stage === 'unknown') continue; ?>
        <span style="display:inline-block;width:10px;height:10px;background:<?= $color ?>;border-radius:2px;margin-right:4px;"></span><?= htmlspecialchars($stageLabel[$stage]) ?> &nbsp;
      <?php endforeach; ?>
    </p>
    <?php
    return ob_get_clean();
}

// ---- the dispatcher ----
// $key: analysis_key (matches homeassistant/nodered/analysis_engine_flow.json's
// ANALYSES registry keys 1:1). $result: already json_decode()'d result_json,
// or null if nothing's landed yet.
function render_analysis_result($key, $result) {
    if (!$result) return '<p class="hint">This analysis has not run yet — the Flux engine that computes it is built but hasn\'t been run against a live Home Assistant instance yet.</p>';
    if (isset($result['error']) && $result['error'] === 'shape_failed') {
        return '<p class="hint">The engine hit an error computing this analysis: ' . htmlspecialchars($result['message'] ?? 'unknown') . '</p>';
    }

    switch ($key) {
        case 'sleep_duration_trend': return av_trend_block($result, 'h', 2);
        case 'sleep_efficiency_trend': return av_trend_block($result, '%', 0);
        case 'hrv_trend': return av_trend_block($result, 'ms', 0);
        case 'resting_hr_trend': return av_trend_block($result, ' bpm', 0);
        case 'incident_intensity_duration_trend':
            $html = '<p class="hint">Anxiety intensity</p>' . av_trend_block($result['intensity_trend'] ?? null, '', 1);
            $html .= '<p class="hint" style="margin-top:16px;">Duration</p>' . av_trend_block($result['duration_trend'] ?? null, ' min', 0);
            return $html;

        case 'body_composition_trend':
            $html = av_trend_block($result['weight_trend'] ?? null, ' lb', 1);
            if (!empty($result['fields_present'])) {
                $rows = [];
                foreach ($result['fields_present'] as $f) {
                    if ($f === 'weight_lb') continue;
                    $series = $result['by_field'][$f] ?? [];
                    $latest = $series ? end($series) : null;
                    $rows[] = [htmlspecialchars($f), $latest ? fmt_num($latest['value'], 1) : '—'];
                }
                if ($rows) $html .= '<p class="hint" style="margin-top:16px;">Other tracked metrics (latest reading):</p>' . av_table(['Metric', 'Latest'], $rows);
            }
            return $html;

        case 'exercise_activity_trend':
            $html = '';
            foreach ([['steps', '', 0], ['exercise_minutes', ' min', 0], ['standing_minutes', ' min', 0]] as [$f, $unit, $dec]) {
                if (empty($result[$f])) continue;
                $html .= '<p class="hint">' . htmlspecialchars($result[$f]['label'] ?? $f) . '</p>' . av_trend_block($result[$f], $unit, $dec) . '<div style="margin-top:16px;"></div>';
            }
            return $html ?: '<p class="hint">No data yet.</p>';

        case 'weight_vs_medication_cadence':
            $html = av_trend_block($result['weight_trend'] ?? null, ' lb', 1);
            $events = $result['cadence_events'] ?? [];
            if ($events) {
                $rows = array_map(fn($e) => [
                    htmlspecialchars(date('M j, Y', strtotime($e['date']))), htmlspecialchars($e['medication']),
                    fmt_num($e['weight_before_avg'], 1) . ' lb', fmt_num($e['weight_after_avg'], 1) . ' lb',
                ], $events);
                $html .= '<p class="hint" style="margin-top:16px;">Weight around each dosing event (3-day avg before/after):</p>' . av_table(['Date', 'Medication', 'Before', 'After'], $rows);
            }
            return $html;

        case 'medication_dosage_change_correlation':
            $changes = $result['changes'] ?? [];
            if (!$changes) return '<p class="hint">No dosage changes in this period.</p>';
            $rows = array_map(fn($c) => [
                htmlspecialchars(date('M j, Y', strtotime($c['date']))), htmlspecialchars($c['medication']),
                fmt_num($c['weight_before'], 1) . ' → ' . fmt_num($c['weight_after'], 1),
                fmt_num($c['hrv_before'], 0) . ' → ' . fmt_num($c['hrv_after'], 0),
                fmt_num($c['mood_before'], 1) . ' → ' . fmt_num($c['mood_after'], 1),
                (int)($c['incidents_before'] ?? 0) . ' → ' . (int)($c['incidents_after'] ?? 0),
            ], $changes);
            return '<p class="hint">14-day average before → after each dosage change.</p>' . av_table(['Date', 'Medication', 'Weight', 'HRV', 'Mood', 'Incidents'], $rows);

        case 'medication_adherence_vs_incidents':
            return av_stat_row([
                ['Missed-dose days', (int)($result['missed_dose_days'] ?? 0)],
                ['Full-adherence days', (int)($result['full_adherence_days'] ?? 0)],
                ['Incident rate — missed', fmt_num($result['incident_rate_on_missed_days'], 2)],
                ['Incident rate — full adherence', fmt_num($result['incident_rate_on_full_adherence_days'], 2)],
            ]);

        case 'alcohol_caffeine_vs_sleep':
            return av_correlation_block($result['alcohol_vs_sleep_efficiency'] ?? null) . av_correlation_block($result['caffeine_vs_sleep_efficiency'] ?? null);

        case 'mood_trend_correlation':
            $html = '<p class="hint">Mood rating</p>' . av_trend_block($result['mood_trend'] ?? null, '/10', 1);
            $html .= '<p class="hint" style="margin-top:16px;">State of mind</p>' . av_trend_block($result['state_of_mind_trend'] ?? null, '/5', 1);
            $html .= '<div style="margin-top:16px;">';
            foreach (['mood_vs_sleep_efficiency', 'mood_vs_alcohol', 'mood_vs_caffeine', 'mood_vs_incidents'] as $k) {
                $html .= av_correlation_block($result[$k] ?? null);
            }
            return $html . '</div>';

        case 'symptom_category_clustering':
            $byCat = $result['by_category'] ?? [];
            $html = av_bar_list(array_map(fn($k) => [ucfirst($k), $byCat[$k]], array_keys($byCat)));
            $pairs = $result['symptom_pairs'] ?? [];
            if ($pairs) {
                $rows = array_map(fn($p) => [htmlspecialchars(str_replace('+', ' + ', $p['pair'])), (int)$p['count']], array_slice($pairs, 0, 10));
                $html .= '<p class="hint" style="margin-top:16px;">Most common symptom pairs:</p>' . av_table(['Symptoms', 'Times seen together'], $rows);
            }
            return $html;

        case 'incident_day_time_clustering':
            $html = av_bar_list(array_map(fn($d) => [$d['label'], $d['count']], $result['by_day_of_week'] ?? []));
            $byHour = array_filter($result['by_hour'] ?? [], fn($h) => $h['count'] > 0);
            if ($byHour) {
                $html .= '<p class="hint" style="margin-top:16px;">By hour of day:</p>' . av_bar_list(array_map(fn($h) => [sprintf('%02d:00', $h['hour']), $h['count']], $byHour));
            }
            return $html;

        case 'therapy_session_effects':
            $html = av_stat_row([
                ['Sessions', (int)($result['session_count'] ?? 0)],
                ['Avg mood change', fmt_num($result['avg_mood_delta'], 1)],
            ]);
            $sessions = $result['sessions'] ?? [];
            $around = $result['incidents_around_sessions'] ?? [];
            if ($sessions) {
                $rows = array_map(function ($s) use ($around) {
                    $a = null;
                    foreach ($around as $x) { if ($x['date'] === $s['date']) { $a = $x; break; } }
                    return [
                        htmlspecialchars(date('M j, Y', strtotime($s['date']))), htmlspecialchars(ucfirst($s['type'])),
                        fmt_num($s['mood_before'] ?? null, 0), fmt_num($s['mood_after'] ?? null, 0),
                        $a ? ((int)$a['incidents_before_7d'] . ' → ' . (int)$a['incidents_after_7d']) : '—',
                    ];
                }, $sessions);
                $html .= av_table(['Date', 'Type', 'Mood before', 'Mood after', 'Incidents 7d before → after'], $rows);
            }
            return $html;

        case 'night_waking_context':
            $entries = $result['entries'] ?? [];
            if (!$entries) return '<p class="hint">No night-waking notes logged in this period.</p>';
            $rows = array_map(fn($e) => [htmlspecialchars(date('M j, Y', strtotime($e['date']))), htmlspecialchars($e['note'])], $entries);
            return av_table(['Date', 'Note'], $rows);

        case 'full_correlation_matrix':
            $matrix = $result['matrix'] ?? [];
            usort($matrix, fn($a, $b) => abs($b['correlation'] ?? 0) <=> abs($a['correlation'] ?? 0));
            $rows = array_map(fn($m) => [
                htmlspecialchars($m['a']) . ' vs ' . htmlspecialchars($m['b']),
                $m['correlation'] === null ? '—' : fmt_num($m['correlation'], 2) . ' (' . av_corr_strength_label($m['correlation']) . ')',
                (int)($m['paired_days'] ?? 0),
            ], $matrix);
            $html = av_table(['Factors', 'Correlation', 'Paired days'], $rows);
            if (!empty($result['note'])) $html .= '<p class="hint">' . htmlspecialchars($result['note']) . '</p>';
            return $html;

        case 'unreported_anxiety_detection':
            $html = av_stat_row([
                ['HRV — incident days', fmt_num($result['incident_day_baseline']['hrv']['avg'] ?? null, 0)],
                ['HRV — normal days', fmt_num($result['normal_day_baseline']['hrv']['avg'] ?? null, 0)],
                ['Resting HR — incident days', fmt_num($result['incident_day_baseline']['resting_hr']['avg'] ?? null, 0)],
                ['Resting HR — normal days', fmt_num($result['normal_day_baseline']['resting_hr']['avg'] ?? null, 0)],
            ]);
            $flagged = $result['flagged_days'] ?? [];
            if ($flagged) {
                $rows = array_map(fn($f) => [htmlspecialchars($f['date']), fmt_num($f['hrv'], 0), fmt_num($f['resting_hr'], 0), fmt_num($f['sleep_efficiency'], 0)], $flagged);
                $html .= '<p class="hint" style="margin-top:16px;">Days that pattern-match known incident days, with no incident logged:</p>' . av_table(['Date', 'HRV', 'Resting HR', 'Sleep efficiency'], $rows);
            } else {
                $html .= '<p class="hint" style="margin-top:16px;">No unreported-pattern days found in this period.</p>';
            }
            if (!empty($result['note'])) $html .= '<p class="hint">' . htmlspecialchars($result['note']) . '</p>';
            return $html;

        case 'bedtime_wake_time_trend':
            // Range bar, not two lines (Ward, Aug 2026) — each night's
            // bedtime/wake reads as one connected span. Values are stored
            // as hours-since-noon (avoids the midnight-wraparound a raw
            // clock hour hits); fmt_clock_from_noon_offset() converts
            // back to a real time label for the axis/tooltips/stats.
            $svg = av_range_bar_svg($result['series'] ?? [], 'bedtime_offset', 'wake_offset', 'fmt_clock_from_noon_offset');
            $html = $svg ? '<div class="report-box">' . $svg . '</div>' : '<p class="hint">Not enough nights in this period yet.</p>';
            $html .= av_stat_row([
                ['Avg bedtime', fmt_clock_from_noon_offset($result['avg_bedtime_offset'] ?? null)],
                ['Avg wake time', fmt_clock_from_noon_offset($result['avg_wake_offset'] ?? null)],
            ]);
            if (!empty($result['note'])) $html .= '<p class="hint">' . htmlspecialchars($result['note']) . '</p>';
            return $html;

        case 'sleep_stage_hypnogram':
            $svg = av_hypnogram_svg($result['buckets'] ?? []);
            $html = $svg ? '<div class="report-box">' . $svg . '</div>' : '<p class="hint">' . htmlspecialchars($result['note'] ?? 'No decomposed sleep-stage data yet.') . '</p>';
            if ($svg && !empty($result['note'])) $html .= '<p class="hint">' . htmlspecialchars($result['note']) . '</p>';
            return $html;

        default:
            return '<p class="hint">Raw result stored (' . strlen(json_encode($result)) . ' bytes) — no dedicated chart for this analysis key yet.</p>';
    }
}
