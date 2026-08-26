<?php
// Demo data generator / reset (Aug 2026). Run this once after creating
// the demo database (see README.md), and again any time you want a clean
// slate before a presentation — it's fully idempotent: clears the demo
// data tables and regenerates ~3 months of fresh synthetic data every run.
//
// Deliberately connects to DEMO_DB_* directly rather than going through
// db.php's get_db() (which only switches databases based on the CURRENT
// browser session's demo_mode flag) — this script has to work regardless
// of what session is or isn't active, and must NEVER be able to land on
// the live database by accident.
//
// Everything generated here is fictional — a made-up persona, not Ward's
// real medications, real incidents, or real journal content. This runs
// against a database that may end up genuinely public-facing (a "click
// through and see the app" link), so nothing real belongs in it.

require_once __DIR__ . '/../config/config.php';

header('Content-Type: text/plain; charset=utf-8');

if (!defined('DEMO_DB_NAME') || DEMO_DB_NAME === '') {
    die("DEMO_DB_NAME isn't configured yet in config/config.php — see demo/README.md.\n");
}
if (DEMO_DB_NAME === DB_NAME && DEMO_DB_HOST === DB_HOST) {
    // The one mistake this script must never allow: TRUNCATE-ing the real
    // database because the demo constants were left pointed at it.
    die("DEMO_DB_NAME is identical to the live DB_NAME — refusing to run. Fix config/config.php first.\n");
}
$providedKey = $_GET['key'] ?? (isset($argv[1]) ? $argv[1] : '');
if (!defined('DEMO_RESET_KEY') || DEMO_RESET_KEY === '' || !hash_equals(DEMO_RESET_KEY, (string)$providedKey)) {
    die("Missing or wrong ?key= (must match DEMO_RESET_KEY in config/config.php).\n");
}

$pdo = new PDO(
    'mysql:host=' . DEMO_DB_HOST . ';dbname=' . DEMO_DB_NAME . ';charset=utf8mb4',
    DEMO_DB_USER,
    DEMO_DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

echo "Connected to demo database (" . DEMO_DB_NAME . "). Clearing old demo data...\n";

// Children before parents (FK: medication_dosage_history -> medications,
// proposed_events -> incidents).
foreach ([
    'wardstock_medication_dosage_history', 'wardstock_proposed_events', 'wardstock_blood_pressure_readings',
    'wardstock_attention_snoozes', 'wardstock_therapy_sessions', 'wardstock_therapy_schedules',
    'wardstock_incidents', 'wardstock_daily_logs', 'wardstock_medications',
] as $table) {
    $pdo->exec("DELETE FROM $table");
    $pdo->exec("ALTER TABLE $table AUTO_INCREMENT = 1");
}

$today = new DateTime('today');
$daysBack = 90;
$startDate = (clone $today)->modify("-$daysBack days");

function randf($min, $max, $decimals = 1) {
    return round($min + mt_rand() / mt_getrandmax() * ($max - $min), $decimals);
}

// --- Medications: a fictional regimen, not Ward's real one ---------------
$demoMeds = [
    ['name' => 'Metformin',            'dosage' => '500mg', 'med_type' => 'scheduled', 'cadence' => 'daily',    'frequency_days' => 1],
    ['name' => 'Lisinopril',           'dosage' => '10mg',  'med_type' => 'scheduled', 'cadence' => 'daily',    'frequency_days' => 1],
    ['name' => 'Atorvastatin',         'dosage' => '20mg',  'med_type' => 'scheduled', 'cadence' => 'daily',    'frequency_days' => 1],
    ['name' => 'Sertraline',           'dosage' => '50mg',  'med_type' => 'scheduled', 'cadence' => 'daily',    'frequency_days' => 1],
    ['name' => 'Vitamin D3',           'dosage' => '2000IU','med_type' => 'scheduled', 'cadence' => 'daily',    'frequency_days' => 1],
    ['name' => 'Semaglutide (demo)',   'dosage' => '0.5mg', 'med_type' => 'scheduled', 'cadence' => 'weekly',   'frequency_days' => 7],
    ['name' => 'Ibuprofen',            'dosage' => '200mg', 'med_type' => 'as_needed', 'cadence' => 'as needed','frequency_days' => 1],
];
$medIds = [];      // name => id, every medication
$dailyMedIds = [];  // id list, scheduled ("taken daily-ish") meds only -- what daily_logs picks from
$stmt = $pdo->prepare('INSERT INTO wardstock_medications (name, dosage, med_type, cadence, frequency_days, start_date, sort_order) VALUES (?,?,?,?,?,?,?)');
foreach ($demoMeds as $i => $m) {
    $stmt->execute([$m['name'], $m['dosage'], $m['med_type'], $m['cadence'], $m['frequency_days'], $startDate->format('Y-m-d'), $i + 1]);
    $newId = (int)$pdo->lastInsertId();
    $medIds[$m['name']] = $newId;
    if ($m['med_type'] === 'scheduled') $dailyMedIds[] = $newId;
}
echo "Inserted " . count($demoMeds) . " demo medications.\n";

// One fictional dosage change, for the medication_dosage_history feature.
$pdo->prepare('INSERT INTO wardstock_medication_dosage_history (medication_id, old_dosage, new_dosage, changed_at, notes) VALUES (?,?,?,?,?)')
    ->execute([$medIds['Lisinopril'], '5mg', '10mg', (clone $startDate)->modify('+30 days')->format('Y-m-d'), 'Dose increased at follow-up (demo data).']);

// --- Daily logs: one row per day for the whole window ---------------------
$stmt = $pdo->prepare('INSERT INTO wardstock_daily_logs
    (log_date, sleep_duration_hrs, sleep_efficiency, resting_hr, hrv, weight, steps, exercise_minutes,
     caffeine, caffeine_servings, alcohol, alcohol_drinks, medications_all_taken, medications_taken_json,
     mood_rating, state_of_mind, free_notes)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');

$baseWeight = 185.0;
for ($d = 0; $d <= $daysBack; $d++) {
    $date = (clone $startDate)->modify("+$d days");
    if ($date > $today) break;

    $sleep = randf(6.0, 8.5);
    $efficiency = mt_rand(78, 95);
    $restingHr = mt_rand(56, 72);
    $hrv = mt_rand(28, 65);
    $baseWeight += randf(-0.4, 0.35, 2); // slow random walk, not a straight trend
    $steps = mt_rand(2500, 11000);
    $exercise = mt_rand(0, 10) > 6 ? mt_rand(15, 60) : null;
    $hadCaffeine = mt_rand(0, 10) > 2;
    $hadAlcohol = mt_rand(0, 10) > 8;
    $allTaken = mt_rand(0, 10) > 1 ? 1 : 0;
    $takenIds = $allTaken ? array_values($dailyMedIds) : array_slice(array_values($dailyMedIds), 0, mt_rand(1, max(1, count($dailyMedIds) - 1)));
    $mood = mt_rand(2, 5);
    $stateOfMind = mt_rand(2, 5);
    $notes = mt_rand(0, 10) > 8 ? 'Quiet day, nothing notable (demo data).' : null;

    $stmt->execute([
        $date->format('Y-m-d'), $sleep, $efficiency, $restingHr, $hrv, round($baseWeight, 1), $steps, $exercise,
        $hadCaffeine ? 'Coffee' : null, $hadCaffeine ? randf(1, 3, 1) : null,
        $hadAlcohol ? 'Wine' : null, $hadAlcohol ? randf(1, 2, 1) : null,
        $allTaken, json_encode($takenIds),
        $mood, $stateOfMind, $notes,
    ]);
}
echo "Inserted daily logs for " . ($daysBack + 1) . " days.\n";

// --- Blood pressure readings: a few times a week -----------------------
$stmt = $pdo->prepare('INSERT INTO wardstock_blood_pressure_readings (reading_at, systolic, diastolic, pulse, position, source) VALUES (?,?,?,?,?,?)');
$bpCount = 0;
for ($d = 0; $d <= $daysBack; $d++) {
    if (mt_rand(0, 10) > 6) continue; // roughly a few times a week, not daily
    $date = (clone $startDate)->modify("+$d days")->setTime(mt_rand(6, 21), mt_rand(0, 59));
    if ($date > $today) break;
    $systolic = mt_rand(110, 138);
    $diastolic = mt_rand(70, 88);
    $stmt->execute([$date->format('Y-m-d H:i:s'), $systolic, $diastolic, mt_rand(58, 78), 'seated', 'manual']);
    $bpCount++;
}
echo "Inserted $bpCount blood pressure readings.\n";

// --- Incidents: sparse, mixed categories, generic fictional content -----
$incidentTemplates = [
    ['category' => 'anxiety', 'trigger_context' => 'Felt anxious before a work presentation.', 'anxiety_intensity' => 6, 'duration_minutes' => 25, 'chest_sensation' => 'mild', 'shaking' => 'mild'],
    ['category' => 'anxiety', 'trigger_context' => 'Woke up with racing thoughts, no clear trigger.', 'anxiety_intensity' => 5, 'duration_minutes' => 40, 'chest_sensation' => 'none', 'shaking' => 'none'],
    ['category' => 'cardiac', 'trigger_context' => 'Mild chest tightness after climbing stairs, resolved with rest.', 'anxiety_intensity' => 4, 'duration_minutes' => 10, 'chest_sensation' => 'moderate', 'medical_evaluation' => 'no'],
    ['category' => 'anxiety', 'trigger_context' => 'Stressful commute, heavy traffic.', 'anxiety_intensity' => 4, 'duration_minutes' => 15, 'chest_sensation' => 'none', 'shaking' => 'none'],
    ['category' => 'medical', 'trigger_context' => 'Mild stomach upset after trying a new restaurant.', 'anxiety_intensity' => 2, 'duration_minutes' => 60, 'stomach_sensation' => 'mild', 'medical_evaluation' => 'no'],
    ['category' => 'anxiety', 'trigger_context' => 'Argument with a family member.', 'anxiety_intensity' => 7, 'duration_minutes' => 30, 'chest_sensation' => 'mild', 'shaking' => 'moderate'],
    ['category' => 'cardiac', 'trigger_context' => 'Brief palpitation sensation while resting, self-resolved.', 'anxiety_intensity' => 3, 'duration_minutes' => 5, 'chest_sensation' => 'mild', 'medical_evaluation' => 'yes', 'medical_evaluation_notes' => 'Checked with PCP; attributed to caffeine (demo data).'],
    ['category' => 'anxiety', 'trigger_context' => 'Crowded event, felt overwhelmed.', 'anxiety_intensity' => 6, 'duration_minutes' => 20, 'chest_sensation' => 'none', 'shaking' => 'mild'],
    ['category' => 'medical', 'trigger_context' => 'Headache after a poor night of sleep.', 'anxiety_intensity' => 2, 'duration_minutes' => 90, 'headache_sensation' => 'moderate'],
    ['category' => 'anxiety', 'trigger_context' => 'Difficult phone call.', 'anxiety_intensity' => 5, 'duration_minutes' => 20, 'chest_sensation' => 'mild', 'shaking' => 'none'],
];
$stmt = $pdo->prepare('INSERT INTO wardstock_incidents
    (category, occurred_at, trigger_context, chest_sensation, arm_sensation, shoulder_sensation, headache_sensation,
     shaking, stomach_sensation, anxiety_intensity, duration_minutes, differed_from_pattern, medical_evaluation,
     medical_evaluation_notes, what_helped_recovery)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
$incidentIds = [];
foreach ($incidentTemplates as $t) {
    $dayOffset = mt_rand(2, $daysBack - 2);
    $occurred = (clone $startDate)->modify("+$dayOffset days")->setTime(mt_rand(7, 22), mt_rand(0, 59));
    $stmt->execute([
        $t['category'], $occurred->format('Y-m-d H:i:s'), $t['trigger_context'],
        $t['chest_sensation'] ?? 'none', $t['arm_sensation'] ?? 'none', $t['shoulder_sensation'] ?? 'none', $t['headache_sensation'] ?? 'none',
        $t['shaking'] ?? 'none', $t['stomach_sensation'] ?? 'none', $t['anxiety_intensity'], $t['duration_minutes'],
        'unknown', $t['medical_evaluation'] ?? 'no', $t['medical_evaluation_notes'] ?? null,
        mt_rand(0, 1) ? 'Deep breathing and resting quietly (demo data).' : null,
    ]);
    $incidentIds[] = (int)$pdo->lastInsertId();
}
echo "Inserted " . count($incidentTemplates) . " incidents.\n";

// --- Therapy: a standing weekly schedule + past sessions -----------------
$pdo->prepare('INSERT INTO wardstock_therapy_schedules (session_type, start_date, frequency_days, active) VALUES (?,?,?,1)')
    ->execute(['individual', $startDate->format('Y-m-d'), 7]);

$therapyTemplates = [
    ['summary' => 'Discussed recent stress patterns and coping strategies.', 'insights' => 'Noticed a connection between poor sleep and next-day anxiety.', 'homework' => 'Try a consistent wind-down routine before bed.'],
    ['summary' => 'Reviewed the week\'s incident log together.', 'insights' => 'Most incidents cluster around a specific weekday.', 'homework' => 'Note energy levels each morning this week.'],
    ['summary' => 'Worked through a breathing exercise for acute anxiety.', 'insights' => 'The exercise reliably shortened incident duration.', 'homework' => 'Practice the exercise daily, not just during incidents.'],
];
$stmt = $pdo->prepare('INSERT INTO wardstock_therapy_sessions (session_date, session_type, summary, insights, homework, mood_before, mood_after) VALUES (?,?,?,?,?,?,?)');
for ($i = 0; $i < 12; $i++) {
    $dayOffset = $daysBack - ($i * 7) - mt_rand(0, 2);
    if ($dayOffset < 0) break;
    $date = (clone $startDate)->modify("+$dayOffset days")->setTime(16, 0);
    $t = $therapyTemplates[$i % count($therapyTemplates)];
    $stmt->execute([$date->format('Y-m-d H:i:s'), 'individual', $t['summary'], $t['insights'], $t['homework'], mt_rand(2, 4), mt_rand(3, 5)]);
}
echo "Inserted therapy schedule + sessions.\n";

// --- One proposed (hypothetical correlation) event, so that queue isn't empty
if (!empty($incidentIds)) {
    $pdo->prepare('INSERT INTO wardstock_proposed_events (analysis_key, proposed_at, suggested_occurred_at, suggested_category, description, confidence, status) VALUES (?,?,?,?,?,?,?)')
        ->execute(['sleep_vs_anxiety_demo', (new DateTime('-2 days'))->format('Y-m-d H:i:s'), (new DateTime('-2 days 08:00'))->format('Y-m-d H:i:s'), 'anxiety', 'Short sleep the night before appears to precede several anxiety incidents (demo data — illustrative only).', 0.62, 'pending']);
}

// --- Settings: keep it simple and consistent -------------------------
$pdo->prepare('INSERT INTO wardstock_app_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
    ->execute(['preferred_timezone', 'America/New_York']);
$pdo->prepare('INSERT INTO wardstock_app_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
    ->execute(['db_version', '4.0']);

echo "\nDone. Demo database now has ~3 months of fictional sample data.\n";
echo "Note: wherewhen's Analysis tab (analysis_results table) is intentionally left empty here —\n";
echo "that data normally arrives from the HA/Node-RED side, which the demo doesn't simulate yet.\n";
