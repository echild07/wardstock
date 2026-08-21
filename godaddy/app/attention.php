<?php
// Attention/reminder system (Fulgrim, Aug 2026 feature list §3.2/3.2.0) —
// "highlight to the user they have to update missed information." Two
// independent things live here:
//
// 1. Daily-item reminders: medicine not (fully) taken today, no weigh-in
//    today, a scheduled therapy session with no notes logged past the
//    day's 5pm end-of-day cutoff, and Oura sync gone stale. Each is
//    snoozable — Ward's own framing — but only until the NEXT day: the
//    snooze key is dated to the day the item was due, so tomorrow's
//    occurrence (a fresh key) always starts unsnoozed even if today's
//    was dismissed.
// 2. Pending analysis-proposed events (feature list §3.2.2): the
//    not-yet-built Flux engine will eventually write "hypothetical"
//    correlated events here for Ward to confirm or deny. Deliberately
//    NOT snoozable and NOT date-scoped — Ward's framing was "should only
//    show/be called upon when there [are pending events]," i.e. it's a
//    standing queue, not a daily reminder that resets.
//
// Used by index.php (the full banner), partials_nav.php (the blinking
// top-right icon — same computation, just checked for non-emptiness),
// and analysis.php (the confirm/deny queue itself).
require_once __DIR__ . '/db.php';

// Scheduled meds actually due on $day, by medication id. Moved here from
// index.php (Fulgrim) since the attention banner needs the same due-date
// math as the 7-day pill grid — one implementation, not two.
function meds_valid_on($allMeds, $day) {
    $ids = [];
    foreach ($allMeds as $m) {
        if (medication_due_on($m, $day)) $ids[] = (int)$m['id'];
    }
    return $ids;
}

// Therapy session types due on $day per active schedules. Moved here from
// index.php for the same reason as meds_valid_on() above.
function therapy_due_types($schedules, $day) {
    $due = [];
    foreach ($schedules as $s) {
        if ($day < $s['start_date']) continue;
        $diffDays = (strtotime($day) - strtotime($s['start_date'])) / 86400;
        if ($diffDays >= 0 && $diffDays % $s['frequency_days'] == 0) $due[] = $s['session_type'];
    }
    return array_unique($due);
}

function is_reminder_snoozed($pdo, $key) {
    $stmt = $pdo->prepare('SELECT 1 FROM attention_snoozes WHERE reminder_key = ?');
    $stmt->execute([$key]);
    return (bool)$stmt->fetch();
}

function snooze_reminder($pdo, $key) {
    // CURDATE() used to stamp this with MySQL's own server-configured
    // timezone (real bug, Aug 2026, same class as everything else in
    // this pass — Ward's day, not the server's, decides which day a
    // snooze belongs to). Bound param using app_today() instead.
    $stmt = $pdo->prepare('INSERT IGNORE INTO attention_snoozes (reminder_key, snoozed_on) VALUES (?, ?)');
    $stmt->execute([$key, app_today($pdo)]);
}

// Full list of active (unsnoozed) daily-item reminders, today's date.
// Each: key (snooze identity), label, href (where to go fix it).
function get_attention_items($pdo) {
    $today = app_today($pdo); // was date('Y-m-d') — server default (UTC), not Ward's actual day (Aug 2026 fix)

    // ---- Medicine not fully taken today ----
    $allMeds = $pdo->query("SELECT * FROM medications WHERE med_type = 'scheduled' ORDER BY sort_order")->fetchAll();
    $validIds = meds_valid_on($allMeds, $today);
    if ($validIds) {
        $stmt = $pdo->prepare('SELECT * FROM daily_logs WHERE log_date = ?');
        $stmt->execute([$today]);
        $log = $stmt->fetch();
        $taken = [];
        if ($log && $log['medications_taken_json']) {
            $d = json_decode($log['medications_taken_json'], true);
            if (is_array($d)) $taken = array_map('intval', $d);
        }
        $takenDue = array_intersect($validIds, $taken);
        if (count($takenDue) < count($validIds)) {
            $key = "med_$today";
            if (!is_reminder_snoozed($pdo, $key)) {
                $href = $log ? 'daily_form.php?id=' . (int)$log['id'] . '&jump=section-medication'
                             : 'daily_form.php?date=' . $today . '&jump=section-medication';
                $items[] = ['key' => $key, 'snoozable' => true, 'href' => $href,
                    'label' => count($takenDue) === 0 ? 'No medicine logged as taken today' : 'Some of today\'s medicine still unlogged'];
            }
        }
    }

    // ---- No weigh-in today ----
    $stmt = $pdo->prepare('SELECT * FROM daily_logs WHERE log_date = ?');
    $stmt->execute([$today]);
    $todayLog = $stmt->fetch();
    if (!$todayLog || $todayLog['weight'] === null) {
        $key = "weight_$today";
        if (!is_reminder_snoozed($pdo, $key)) {
            $href = $todayLog ? 'daily_form.php?id=' . (int)$todayLog['id'] . '&jump=section-weight'
                               : 'daily_form.php?date=' . $today . '&jump=section-weight';
            $items[] = ['key' => $key, 'snoozable' => true, 'href' => $href, 'label' => 'No weigh-in logged today'];
        }
    }

    // ---- Therapy scheduled today or yesterday, no notes logged, past the
    // day's 5pm EoD cutoff (today only — yesterday's cutoff has obviously
    // already passed) ----
    $schedules = $pdo->query('SELECT * FROM therapy_schedules WHERE active = 1')->fetchAll();
    // Both were server-clock bugs (Aug 2026 fix) — $yesterday used
    // date('Y-m-d', strtotime('-1 day')) (server "today" minus a day,
    // not Ward's), and the cutoff check used date('G') (server's current
    // hour, mislabeled "local" in the old comment — it never was).
    $appNow = app_now($pdo);
    $yesterday = (clone $appNow)->modify('-1 day')->format('Y-m-d');
    $pastCutoffToday = (int)$appNow->format('G') >= 17; // 5pm Ward's actual local time
    foreach ([$today => $pastCutoffToday, $yesterday => true] as $day => $applies) {
        if (!$applies) continue;
        foreach (therapy_due_types($schedules, $day) as $type) {
            $stmt = $pdo->prepare("SELECT id FROM therapy_sessions WHERE session_type = ? AND DATE(session_date) = ?");
            $stmt->execute([$type, $day]);
            if ($stmt->fetch()) continue; // already logged
            $key = "therapy_{$type}_$day";
            if (!is_reminder_snoozed($pdo, $key)) {
                $items[] = ['key' => $key, 'snoozable' => true,
                    'href' => 'therapy_form.php?date=' . $day . '&type=' . urlencode($type),
                    'label' => ucfirst($type) . ' therapy (' . ($day === $today ? 'today' : 'yesterday') . ') — no session notes yet'];
            }
        }
    }

    // ---- Oura sync gone stale ----
    $stmt = $pdo->prepare("SELECT * FROM system_status_reports WHERE component = 'oura_sync'");
    $stmt->execute();
    $ouraRow = $stmt->fetch();
    if ($ouraRow) {
        $od = overdue_info($ouraRow);
        if ($od && $od['is_overdue']) {
            $key = "oura_stale_$today";
            if (!is_reminder_snoozed($pdo, $key)) {
                $items[] = ['key' => $key, 'snoozable' => true, 'href' => 'status.php',
                    'label' => 'Oura hasn\'t synced in a while — open the Oura app on your phone, or check Status if it should have run on its own'];
            }
        }
    }

    return $items;
}

// Pending "hypothetical" correlation events awaiting confirm/deny
// (feature list §3.2.2). Not snoozable, not date-scoped.
function get_pending_event_count($pdo) {
    return (int)$pdo->query("SELECT COUNT(*) c FROM proposed_events WHERE status = 'pending'")->fetch()['c'];
}
