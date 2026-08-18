<?php
// Shared Oura Ring API helpers. Requires config.php + db.php already loaded.

function oura_is_configured() {
    return OURA_CLIENT_ID !== '' && OURA_CLIENT_SECRET !== '';
}

function oura_get_tokens($pdo) {
    $stmt = $pdo->query('SELECT * FROM oura_tokens WHERE id = 1');
    return $stmt->fetch() ?: null;
}

function oura_save_tokens($pdo, $accessToken, $refreshToken, $expiresInSeconds) {
    $expiresAt = (new DateTime())->modify("+{$expiresInSeconds} seconds")->format('Y-m-d H:i:s');
    $stmt = $pdo->prepare('
        INSERT INTO oura_tokens (id, access_token, refresh_token, expires_at)
        VALUES (1, :at, :rt, :exp)
        ON DUPLICATE KEY UPDATE access_token = :at2, refresh_token = :rt2, expires_at = :exp2
    ');
    $stmt->execute([
        'at' => $accessToken, 'rt' => $refreshToken, 'exp' => $expiresAt,
        'at2' => $accessToken, 'rt2' => $refreshToken, 'exp2' => $expiresAt,
    ]);
}

function oura_disconnect($pdo) {
    $pdo->exec('DELETE FROM oura_tokens WHERE id = 1');
}

// Records that a REAL network call to Oura was made (not just a cache-hit
// check of a still-valid token) and whether it succeeded. No-ops silently
// if there's no oura_tokens row yet (nothing to record against).
function oura_record_attempt($pdo, $success) {
    $now = (new DateTime())->format('Y-m-d H:i:s');
    if ($success) {
        $stmt = $pdo->prepare('UPDATE oura_tokens SET last_attempt_at = :now, last_attempt_ok = 1, last_success_at = :now2 WHERE id = 1');
        $stmt->execute(['now' => $now, 'now2' => $now]);
    } else {
        $stmt = $pdo->prepare('UPDATE oura_tokens SET last_attempt_at = :now, last_attempt_ok = 0 WHERE id = 1');
        $stmt->execute(['now' => $now]);
    }
}

// Low-level POST to Oura's token endpoint (used for both the initial code
// exchange and refresh-token renewal — same endpoint, different grant_type).
function oura_token_request($params) {
    $ch = curl_init('https://api.ouraring.com/oauth/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($response === false || $httpCode >= 400) return null;
    return json_decode($response, true);
}

// Returns a currently-valid access token, transparently refreshing if the
// stored one is expired (or expiring within the next 60 seconds). Returns
// null if never connected, or if the refresh itself fails (refresh tokens
// are single-use and rotate on every refresh, so this also re-saves the new
// refresh token every time — using a stale one would fail).
function oura_ensure_valid_token($pdo) {
    if (!oura_is_configured()) return null;
    $tokens = oura_get_tokens($pdo);
    if (!$tokens) return null;

    $expiresAt = strtotime($tokens['expires_at']);
    if ($expiresAt > time() + 60) {
        return $tokens['access_token'];
    }

    $result = oura_token_request([
        'grant_type' => 'refresh_token',
        'refresh_token' => $tokens['refresh_token'],
        'client_id' => OURA_CLIENT_ID,
        'client_secret' => OURA_CLIENT_SECRET,
    ]);
    if (!$result || !isset($result['access_token'])) {
        oura_record_attempt($pdo, false);
        return null; // refresh failed — connection needs to be re-established
    }
    oura_save_tokens($pdo, $result['access_token'], $result['refresh_token'], $result['expires_in']);
    oura_record_attempt($pdo, true);
    return $result['access_token'];
}

// Authenticated GET against a v2/usercollection endpoint. Returns an array
// with full diagnostic detail rather than just data-or-null — this matters:
// "the API call failed" (bad token, wrong scope, network error) and "the
// API call succeeded but Oura has no data for this date" look identical to
// a caller that only gets null back either way, which makes real problems
// (e.g. a missing scope) indistinguishable from an empty-but-healthy
// response. Shape: ['success' => bool, 'http_code' => int|null,
// 'data' => array|null, 'error' => string|null, 'raw_body' => string|null].
function oura_api_get($pdo, $endpoint, $params = []) {
    $token = oura_ensure_valid_token($pdo);
    if (!$token) {
        return ['success' => false, 'http_code' => null, 'data' => null, 'error' => 'No valid access token (not connected, or refresh failed)', 'raw_body' => null];
    }

    $url = 'https://api.ouraring.com/v2/usercollection/' . $endpoint;
    if ($params) $url .= '?' . http_build_query($params);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    $success = ($response !== false && $httpCode < 300);
    oura_record_attempt($pdo, $success);

    if (!$success) {
        return [
            'success' => false,
            'http_code' => $httpCode ?: null,
            'data' => null,
            'error' => $curlError ?: ('Oura returned HTTP ' . $httpCode),
            'raw_body' => $response !== false ? $response : null,
        ];
    }
    $decoded = json_decode($response, true);
    return [
        'success' => true,
        'http_code' => $httpCode,
        'data' => $decoded['data'] ?? $decoded,
        'error' => null,
        'raw_body' => $response,
    ];
}

// Pulls sleep, activity, and readiness for a single date and maps the
// fields WardStock actually tracks. Field mapping (documented here since
// it can't be tested live from the build environment — verify against your
// real data once connected, via the "raw response" debug view):
//   sleep_duration_hrs <- /sleep total_sleep_duration (seconds -> hours)
//   sleep_efficiency   <- /sleep efficiency (already a percentage)
//   resting_hr         <- /sleep lowest_heart_rate (lowest overnight bpm,
//                          a standard resting-HR proxy; daily_readiness only
//                          exposes a normalized 1-100 contributor score, not
//                          a real bpm value, so /sleep is used instead)
//   hrv                <- /sleep average_hrv (already in ms)
//   steps               <- /daily_activity steps
// Weight isn't tracked by Oura at all. Exercise/standing minutes aren't
// pulled — Oura doesn't have a direct equivalent to Apple's Move/Exercise/
// Stand rings, and daily_activity's activity-time breakdown wasn't
// confirmed against live data, so it's left for you to enter manually
// rather than guess and risk silently-wrong numbers.
function oura_fetch_day($pdo, $date) {
    $result = ['raw' => [], 'mapped' => [], 'diagnostics' => []];

    // Query from the day before through the day after the target date,
    // then filter to records whose own "day" field exactly matches —
    // NEVER a start_date=end_date=target-date query. Two confirmed real
    // issues drove this, both found from Ward's actual data:
    //   1. A session starting the evening before (bedtime_start 9:25pm
    //      the 11th) but tagged "day": "2026-08-12" (the wake day) was
    //      missed entirely by a same-day query for the 12th — Oura's
    //      range filter appears keyed to when the session started, not
    //      its "day" tag.
    //   2. Widening to just [date-1, date] still missed that same
    //      record. Best explanation: bedtime_start in local time
    //      (-04:00) is 9:25pm on the 11th, but in UTC that's already
    //      past midnight — 1:25am on the 12th. If Oura's end_date is an
    //      EXCLUSIVE boundary (only through the *start* of that date,
    //      not through its end), end_date=<the 12th> cuts off right at
    //      2026-08-12T00:00:00, excluding a session that starts 90
    //      minutes later in UTC despite unambiguously being "the night
    //      of the 11th" locally. Widening the end boundary to the day
    //      AFTER the target date guards against this regardless of
    //      which exact interpretation is correct.
    $rangeStart = (new DateTime($date))->modify('-1 day')->format('Y-m-d');
    $rangeEnd = (new DateTime($date))->modify('+1 day')->format('Y-m-d');

    $sleepResp = oura_api_get($pdo, 'sleep', ['start_date' => $rangeStart, 'end_date' => $rangeEnd]);
    $result['diagnostics']['sleep'] = $sleepResp;
    $result['raw']['sleep'] = $sleepResp['data'];
    if ($sleepResp['success'] && $sleepResp['data']) {
        // Multiple sleep periods are possible (naps, plus now a 2-day
        // window). Only consider records actually tagged with the
        // requested date; among those, prefer 'long_sleep'/'sleep' types
        // (ignoring rejected/deleted entries), taking the longest.
        $candidates = array_filter($sleepResp['data'], fn($s) =>
            ($s['day'] ?? null) === $date && in_array($s['type'] ?? '', ['long_sleep', 'sleep']));
        if ($candidates) {
            usort($candidates, fn($a, $b) => ($b['total_sleep_duration'] ?? 0) <=> ($a['total_sleep_duration'] ?? 0));
            $main = $candidates[array_key_first($candidates)];
            if (isset($main['total_sleep_duration'])) {
                $result['mapped']['sleep_duration_hrs'] = round($main['total_sleep_duration'] / 3600, 2);
            }
            if (isset($main['efficiency'])) $result['mapped']['sleep_efficiency'] = (int)$main['efficiency'];
            if (isset($main['lowest_heart_rate'])) $result['mapped']['resting_hr'] = (int)$main['lowest_heart_rate'];
            if (isset($main['average_hrv'])) $result['mapped']['hrv'] = (int)$main['average_hrv'];
        }
    }

    $activityResp = oura_api_get($pdo, 'daily_activity', ['start_date' => $rangeStart, 'end_date' => $rangeEnd]);
    $result['diagnostics']['daily_activity'] = $activityResp;
    $result['raw']['daily_activity'] = $activityResp['data'];
    if ($activityResp['success'] && $activityResp['data']) {
        $activityMatch = array_values(array_filter($activityResp['data'], fn($a) => ($a['day'] ?? null) === $date));
        if ($activityMatch && isset($activityMatch[0]['steps'])) {
            $result['mapped']['steps'] = (int)$activityMatch[0]['steps'];
        }
    }

    $readinessResp = oura_api_get($pdo, 'daily_readiness', ['start_date' => $rangeStart, 'end_date' => $rangeEnd]);
    $result['diagnostics']['daily_readiness'] = $readinessResp;
    $result['raw']['daily_readiness'] = $readinessResp['data'];

    return $result;
}

// Shared merge-safe upsert into daily_logs — used by BOTH the manual
// "Pull from Oura" flow (oura_sync.php) and the new api/oura_push.php
// endpoint (HA's automated push), so there is exactly one place this
// logic can drift or break, not two implementations to keep in sync.
// $mappedFields is the same shape oura_fetch_day()'s ['mapped'] produces —
// only overwrites what's actually provided, preserving everything else
// already on that day (Weight, Caffeine, Alcohol, Medications aren't
// Oura data at all and must never be touched by this).
// Returns the daily_logs row id that was inserted or updated.
function oura_upsert_daily_log($pdo, $date, $mappedFields) {
    $existing = $pdo->prepare('SELECT * FROM daily_logs WHERE log_date = ?');
    $existing->execute([$date]);
    $existingRow = $existing->fetch();

    if ($existingRow) {
        $fields = $mappedFields;
        $set = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($fields)));
        $stmt = $pdo->prepare("UPDATE daily_logs SET $set WHERE id = :id");
        $fields['id'] = $existingRow['id'];
        $stmt->execute($fields);
        return $existingRow['id'];
    } else {
        $fields = array_merge(['log_date' => $date], $mappedFields);
        $colList = implode(', ', array_keys($fields));
        $placeholders = implode(', ', array_map(fn($k) => ":$k", array_keys($fields)));
        $stmt = $pdo->prepare("INSERT INTO daily_logs ($colList) VALUES ($placeholders)");
        $stmt->execute($fields);
        return $pdo->lastInsertId();
    }
}

// Whitelist of daily_logs columns api/oura_push.php is allowed to write —
// HA should only ever push the same summary fields WardStock's own Oura
// integration already understands, never arbitrary columns.
function oura_push_allowed_fields() {
    return ['sleep_duration_hrs', 'sleep_efficiency', 'resting_hr', 'hrv', 'steps'];
}
