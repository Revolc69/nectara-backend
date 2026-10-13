<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json");

mysqli_report(MYSQLI_REPORT_OFF);
ini_set('display_errors', 0);
require_once __DIR__ . '/achievement_helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $conn = new mysqli("localhost", "root", "", "nectara_db");

    if ($conn->connect_error) {
        throw new Exception("DB connection failed: " . $conn->connect_error);
    }

    // ── Categorize a pin's `name` (the AI-identified species, e.g.
    //    "🦋 Monarch Butterfly", "🦋 Honeybee", "🦋 Luna Moth" — note the
    //    butterfly emoji is hardcoded by the app regardless of actual species,
    //    so we strip leading emoji/symbols and match on the text itself).
    function categorize_pollinator($raw) {
        $s = strtolower(trim((string)$raw));
        $s = preg_replace('/^[^a-z0-9]+/u', '', $s);
        if ($s === '') return null;
        if (strpos($s, 'bee') !== false) return 'bee';
        if (strpos($s, 'moth') !== false) return 'moth';
        if (
            strpos($s, 'butterfly') !== false ||
            strpos($s, 'monarch') !== false ||
            strpos($s, 'swallowtail') !== false ||
            strpos($s, 'painted lady') !== false
        ) return 'butterfly';
        return 'other';
    }

    if (!isset($_GET['user_id']) || trim($_GET['user_id']) === '') {
        throw new Exception("Missing user_id.");
    }

    $user_id = (int)$_GET['user_id'];
    $today = date('Y-m-d');

    // ── Login history -> streaks ────────────────────────────────────────
    $stmt = $conn->prepare("SELECT login_date FROM nectara_login_log WHERE user_id = ? ORDER BY login_date DESC");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $dates = [];
    while ($row = $result->fetch_assoc()) {
        $dates[] = $row['login_date'];
    }
    $stmt->close();

    $total_login_days = count($dates);

    $current_streak = 0;
    if ($total_login_days > 0) {
        $cursor = new DateTime($dates[0]);
        $now = new DateTime($today);
        $diffFromToday = (int)$now->diff($cursor)->format('%a');
        if ($diffFromToday <= 1) {
            $current_streak = 1;
            for ($i = 1; $i < $total_login_days; $i++) {
                $prev = new DateTime($dates[$i - 1]);
                $curr = new DateTime($dates[$i]);
                $gap = (int)$prev->diff($curr)->format('%a');
                if ($gap === 1) { $current_streak++; } else { break; }
            }
        }
    }

    $longest_streak = 0;
    $run = 0;
    for ($i = 0; $i < $total_login_days; $i++) {
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

    // ── Member since ─────────────────────────────────────────────────────
    $stmt = $conn->prepare("SELECT created_at FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $userRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $member_since = $userRow['created_at'] ?? null;
    $days_as_member = 0;
    if ($member_since) {
        $createdDate = new DateTime($member_since);
        $nowDate = new DateTime($today);
        $days_as_member = (int)$createdDate->diff($nowDate)->format('%a') + 1;
    }

    // ── Sightings logged (flower_pins owned by this user) ───────────────
    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM flower_pins WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $sightingRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $total_sightings = (int)($sightingRow['cnt'] ?? 0);

    // ── This user's species (pollinator) breakdown — parsed from the `name`
    //    column, which holds the AI-identified species. ecosystem_data is NOT
    //    used — it's just a fixed placeholder string, not per-species data.
    $species_counts = ["bee" => 0, "butterfly" => 0, "moth" => 0, "other" => 0];

    $stmt = $conn->prepare(
        "SELECT name FROM flower_pins WHERE user_id = ? AND name IS NOT NULL AND name != ''"
    );
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $nameResult = $stmt->get_result();
    while ($row = $nameResult->fetch_assoc()) {
        $bucket = categorize_pollinator($row["name"]);
        if ($bucket !== null) {
            $species_counts[$bucket]++;
        }
    }
    $stmt->close();

    $sightings_by_species = [];
    foreach ($species_counts as $species => $count) {
        $sightings_by_species[] = ["species" => $species, "count" => $count];
    }

    // ── Badge thresholds ──────────────────────────────────────────────────
    $best_streak_ever = max($current_streak, $longest_streak);

    $streak_milestones = [3, 7, 14, 30, 100];
    $sighting_milestones = [1, 10, 25, 50, 100];

    $badges = [];

    // When was each badge earned? Streak badges: the first day a run of logins
    // reached that length. Sighting badges: when the Nth pin was created.
    $streak_reach = streak_reach_dates(array_reverse($dates), $streak_milestones);
    $has_created_at = table_has_column($conn, 'flower_pins', 'created_at');
    $my_pins = fetch_pins_by_user($conn, $has_created_at, $user_id)[$user_id] ?? [];

    foreach ($streak_milestones as $m) {
        $badges[] = [
            "id" => "streak_$m",
            "category" => "streak",
            "label" => "$m-Day Streak",
            "threshold" => $m,
            "progress" => min($best_streak_ever, $m),
            "earned" => $best_streak_ever >= $m,
            "earned_at" => $best_streak_ever >= $m ? ($streak_reach[$m] ?? null) : null,
        ];
    }

    foreach ($sighting_milestones as $m) {
        $badges[] = [
            "id" => "sightings_$m",
            "category" => "sightings",
            "label" => $m === 1 ? "First Sighting" : "$m Sightings Logged",
            "threshold" => $m,
            "progress" => min($total_sightings, $m),
            "earned" => $total_sightings >= $m,
            "earned_at" => $total_sightings >= $m ? ($my_pins[$m - 1]['at'] ?? null) : null,
        ];
    }

    // ── Expert eligibility ───────────────────────────────────────────────────
    $stmt = $conn->prepare("SELECT user_type, upgraded_at FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $expertRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $is_expert      = ($expertRow['user_type'] ?? 'regular') === 'expert';
    $upgraded_at    = $expertRow['upgraded_at'] ?? null;

    // Thresholds
    $sighting_threshold = 50;
    $streak_threshold   = 100;
    $sightings_met      = $total_sightings >= $sighting_threshold;
    $streak_met         = $longest_streak  >= $streak_threshold;
    $expert_eligible    = $sightings_met && $streak_met;

    echo json_encode([
        "status" => "success",
        "current_streak" => $current_streak,
        "longest_streak" => $longest_streak,
        "total_login_days" => $total_login_days,
        "member_since" => $member_since,
        "days_as_member" => $days_as_member,
        "total_sightings"    => $total_sightings,
        "badges"             => $badges,
        "is_expert"          => $is_expert,
        "upgraded_at"        => $upgraded_at,
        "expert_eligible"    => $expert_eligible,
        "sightings_met"      => $sightings_met,
        "streak_met"         => $streak_met,
        "sighting_threshold" => $sighting_threshold,
        "streak_threshold"   => $streak_threshold,
        "sightings_by_species" => $sightings_by_species,
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
?>