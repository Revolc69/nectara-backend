<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json");

// Throw exceptions on any mysqli error (bad query, missing column/table, etc.)
// instead of silently returning false and causing a fatal 500 on ->fetch_assoc().
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . '/achievement_helpers.php';
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

    // ── Pins per user in the order they were made — used to work out WHEN each
    //    user reached their current sighting count / sighting badges. ─────────
    $has_created_at = table_has_column($conn, 'flower_pins', 'created_at');
    $pins_by_user = ($metric === 'sightings' || $metric === 'badges')
        ? fetch_pins_by_user($conn, $has_created_at)
        : [];

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
                // The day the current streak hit its current length (= its latest login day).
                'current_reached' => $current_streak > 0 ? $dates[0] : null,
                // First day each streak milestone was ever reached (dates are DESC here).
                'reach'   => streak_reach_dates(array_reverse($dates), [3, 7, 14, 30, 100]),
            ];
        }
    }

    // ── Badge milestones — must match get_achievements.php exactly ────────
    $streak_milestones = [3, 7, 14, 30, 100];
    $sighting_milestones = [1, 10, 25, 50, 100];

    // ── Build value + "when did they reach it" per user ────────────────────
    $rows = [];

    foreach ($users as $uid => $u) {
        $achieved_at = null;
        $order_key   = null; // lower = reached earlier (pin id), used if timestamps are equal/missing
        $pins        = $pins_by_user[$uid] ?? [];

        if ($metric === 'sightings') {
            $value = $sightings_by_user[$uid] ?? 0;
            if ($value > 0 && !empty($pins)) {
                $last        = end($pins);       // the pin that brought them to this count
                $achieved_at = $last['at'];
                $order_key   = $last['id'];
            }
        } elseif ($metric === 'streak') {
            $value       = $streak_by_user[$uid]['current'] ?? 0;
            $achieved_at = $value > 0 ? ($streak_by_user[$uid]['current_reached'] ?? null) : null;
        } else { // badges
            $best_streak = $streak_by_user[$uid]['best'] ?? 0;
            $sightings   = $sightings_by_user[$uid] ?? 0;
            $reach       = $streak_by_user[$uid]['reach'] ?? [];
            $earned = 0;
            $earned_times = [];
            foreach ($streak_milestones as $m) {
                if ($best_streak >= $m) { $earned++; $earned_times[] = $reach[$m] ?? null; }
            }
            foreach ($sighting_milestones as $m) {
                if ($sightings >= $m) { $earned++; $earned_times[] = $pins[$m - 1]['at'] ?? null; }
            }
            $value       = $earned;
            // Time of their most recent badge. Unknown (null) if any badge's time isn't recorded.
            $achieved_at = latest_known_time($earned_times);
        }

        $rows[] = [
            'user_id'     => $uid,
            'username'    => $u['username'],
            'value'       => (int)$value,
            'achieved_at' => $achieved_at,
            'order_key'   => $order_key,
        ];
    }

    // ── Rank: higher value first; on a tie, whoever reached it FIRST. ───────
    // (Previously ties were broken by user_id, i.e. who signed up first.)
    // Keep EVERY user in the response — the frontend uses the first three
    // entries as the podium positions.
    $entries = build_ranked_entries($rows, $current_user_id);

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