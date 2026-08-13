<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json");

// Throw exceptions on any mysqli error (bad query, missing column/table, etc.)
// instead of silently returning false and causing a fatal 500 on ->fetch_assoc().
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
ini_set('display_errors', 0);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $conn = new mysqli("localhost", "root", "", "nectara_db");

    if ($conn->connect_error) {
        throw new Exception("DB connection failed: " . $conn->connect_error);
    }

    if (!isset($_GET['user_id']) || trim($_GET['user_id']) === '') {
        throw new Exception("Missing user_id.");
    }
    $current_user_id = (int)$_GET['user_id'];

    $metric = isset($_GET['metric']) ? trim($_GET['metric']) : 'streak';
    if (!in_array($metric, ['streak', 'sightings', 'badges'], true)) {
        throw new Exception("Invalid metric. Must be streak, sightings, or badges.");
    }

    $today = date('Y-m-d');

    // ── All users ─────────────────────────────────────────────────────────
    // NOTE: users table has no `username` column (id, email, password,
    // created_at, user_type, upgraded_at) — derive the display handle from
    // the email prefix, same as login.php / update_profile.php do.
    $users = [];
    $res = $conn->query("SELECT id, email FROM users");
    while ($row = $res->fetch_assoc()) {
        $emailParts = explode('@', $row['email'] ?? '');
        $users[(int)$row['id']] = [
            'user_id'  => (int)$row['id'],
            'username' => $emailParts[0] !== '' ? $emailParts[0] : 'Explorer',
        ];
    }

    if (empty($users)) {
        echo json_encode([
            "status" => "success",
            "entries" => [],
            "current_user_rank" => null,
            "current_user_value" => null,
            "total_participants" => 0,
        ]);
        $conn->close();
        exit;
    }

    // ── Sightings per user (needed for "sightings" and "badges" metrics) ──
    $sightings_by_user = [];
    $res = $conn->query("SELECT user_id, COUNT(*) AS cnt FROM flower_pins WHERE user_id IS NOT NULL GROUP BY user_id");
    while ($row = $res->fetch_assoc()) {
        $sightings_by_user[(int)$row['user_id']] = (int)$row['cnt'];
    }

    // ── Login dates per user (needed for "streak" and "badges" metrics) ───
    // best_streak_ever mirrors get_achievements.php: max(current_streak, longest_streak)
    $streak_by_user = [];
    if ($metric === 'streak' || $metric === 'badges') {
        $dates_by_user = [];
        $res = $conn->query("SELECT user_id, login_date FROM nectara_login_log WHERE user_id IS NOT NULL ORDER BY user_id, login_date DESC");
        while ($row = $res->fetch_assoc()) {
            $uid = (int)$row['user_id'];
            $dates_by_user[$uid][] = $row['login_date'];
        }

        foreach ($dates_by_user as $uid => $dates) {
            $total = count($dates);

            $current_streak = 0;
            if ($total > 0) {
                $cursor = new DateTime($dates[0]);
                $now = new DateTime($today);
                $diffFromToday = (int)$now->diff($cursor)->format('%a');
                if ($diffFromToday <= 1) {
                    $current_streak = 1;
                    for ($i = 1; $i < $total; $i++) {
                        $prev = new DateTime($dates[$i - 1]);
                        $curr = new DateTime($dates[$i]);
                        $gap = (int)$prev->diff($curr)->format('%a');
                        if ($gap === 1) { $current_streak++; } else { break; }
                    }
                }
            }

            $longest_streak = 0;
            $run = 0;
            for ($i = 0; $i < $total; $i++) {
                if ($i === 0) {
                    $run = 1;
                } else {
                    $prev = new DateTime($dates[$i - 1]);
                    $curr = new DateTime($dates[$i]);
                    $gap = (int)$prev->diff($curr)->format('%a');
                    $run = ($gap === 1) ? $run + 1 : 1;
                }
                if ($run > $longest_streak) { $longest_streak = $run; }
            }

            $streak_by_user[$uid] = [
                'current' => $current_streak,
                'best'    => max($current_streak, $longest_streak),
            ];
        }
    }

    // ── Badge milestones — must match get_achievements.php exactly ────────
    $streak_milestones = [3, 7, 14, 30, 100];
    $sighting_milestones = [1, 10, 25, 50, 100];

    // ── Build value per user for the requested metric ──────────────────────
    $values = []; // user_id => numeric value

    foreach ($users as $uid => $u) {
        if ($metric === 'sightings') {
            $values[$uid] = $sightings_by_user[$uid] ?? 0;
        } elseif ($metric === 'streak') {
            $values[$uid] = $streak_by_user[$uid]['current'] ?? 0;
        } else { // badges
            $best_streak = $streak_by_user[$uid]['best'] ?? 0;
            $sightings   = $sightings_by_user[$uid] ?? 0;
            $earned = 0;
            foreach ($streak_milestones as $m) { if ($best_streak >= $m) $earned++; }
            foreach ($sighting_milestones as $m) { if ($sightings >= $m) $earned++; }
            $values[$uid] = $earned;
        }
    }

    // ── Sort descending, assign ranks (ties share the same rank) ──────────
    // Keep EVERY user in the response. The frontend uses the first three
    // entries as the three podium positions, so ties must not remove slots.
    // Use user_id as a deterministic tie-breaker so the order is stable.
    uksort($values, function ($a, $b) use ($values) {
        if ($values[$a] === $values[$b]) {
            return (int)$a <=> (int)$b;
        }
        return $values[$a] > $values[$b] ? -1 : 1;
    });

    $entries = [];
    $rank = 0;
    $prev_value = null;
    $position = 0;
    foreach ($values as $uid => $value) {
        $position++;
        if ($value !== $prev_value) {
            $rank = $position;
            $prev_value = $value;
        }
        $entries[] = [
            "user_id"         => $uid,
            "username"        => $users[$uid]['username'],
            "value"           => $value,
            "rank"            => $rank,
            "is_current_user" => $uid === $current_user_id,
        ];
    }

    $current_user_rank  = null;
    $current_user_value = null;
    foreach ($entries as $e) {
        if ($e['user_id'] === $current_user_id) {
            $current_user_rank  = $e['rank'];
            $current_user_value = $e['value'];
            break;
        }
    }

    echo json_encode([
        "status" => "success",
        "entries" => $entries,
        "current_user_rank" => $current_user_rank,
        "current_user_value" => $current_user_value,
        "total_participants" => count($entries),
    ]);

    $conn->close();

} catch (Exception $e) {
    if (ob_get_length()) ob_clean();
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
    exit;
}