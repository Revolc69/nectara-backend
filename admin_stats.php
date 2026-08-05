<?php
/**
 * admin_stats.php
 *
 * Returns aggregated stats for the Admin Overview screen:
 *   - user totals (all-time, this month)
 *   - sighting totals (all-time, today, this month)
 *   - flagged pin count
 *   - last-14-days daily series for sightings + signups
 *   - breakdown of pins by verification status (verified / flagged / neutral)
 *
 * REAL SCHEMA (confirmed via DESCRIBE):
 *   users table:        id, email, password, created_at, user_type, upgraded_at
 *   flower_pins table:  id, user_id, name, latitude, longitude, created_at,
 *                        upvotes, downvotes, description, ecosystem_data, img_url
 *
 * flower_pins has no `source` column — every row here is a pin a user actually
 * submitted (OSM/iNaturalist pins are fetched live by the app, never stored here),
 * so no WHERE filter is needed to count "real" sightings.
 *
 * "Flagged" = flower_pins rows where downvotes > upvotes. Change this rule if
 * you'd rather flag on a fixed downvote threshold (e.g. downvotes >= 3).
 */

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json");

// Suppress raw HTML errors from breaking the React Native JSON parser
mysqli_report(MYSQLI_REPORT_OFF);
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

    // Wraps $conn->query() so a bad table/column name throws a readable
    // error instead of returning false and crashing later with a blank response.
    function q($conn, $sql) {
        $res = $conn->query($sql);
        if ($res === false) {
            throw new Exception("SQL error: " . $conn->error . " | Query: " . $sql);
        }
        return $res;
    }

    // ── Categorize a pin's `name` (the AI-identified species, e.g.
    //    "🦋 Monarch Butterfly", "🦋 Honeybee", "🦋 Luna Moth" — note the
    //    butterfly emoji is hardcoded by the app regardless of actual species,
    //    so we strip leading emoji/symbols and match on the text itself).
    function categorize_pollinator($raw) {
        $s = strtolower(trim((string)$raw));
        // Strip any leading emoji/symbol characters and surrounding whitespace
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

    // ── Totals ──────────────────────────────────────────────────────────
    $total_users = (int) q($conn, "SELECT COUNT(*) AS c FROM users")->fetch_assoc()["c"];

    $new_users_this_month = (int) q($conn,
        "SELECT COUNT(*) AS c FROM users
         WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())"
    )->fetch_assoc()["c"];

    $total_sightings = (int) q($conn,
        "SELECT COUNT(*) AS c FROM flower_pins"
    )->fetch_assoc()["c"];

    $sightings_today = (int) q($conn,
        "SELECT COUNT(*) AS c FROM flower_pins WHERE DATE(created_at) = CURDATE()"
    )->fetch_assoc()["c"];

    $sightings_this_month = (int) q($conn,
        "SELECT COUNT(*) AS c FROM flower_pins
         WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())"
    )->fetch_assoc()["c"];

    $flagged_pins = (int) q($conn,
        "SELECT COUNT(*) AS c FROM flower_pins WHERE downvotes > upvotes"
    )->fetch_assoc()["c"];

    // ── Last 14 days: sightings per day ────────────────────────────────
    $sightings_series = [];
    $res = q($conn,
        "SELECT DATE(created_at) AS d, COUNT(*) AS c
         FROM flower_pins
         WHERE created_at >= (CURDATE() - INTERVAL 13 DAY)
         GROUP BY DATE(created_at)"
    );
    $sightingsByDate = [];
    while ($row = $res->fetch_assoc()) {
        $sightingsByDate[$row["d"]] = (int) $row["c"];
    }
    for ($i = 13; $i >= 0; $i--) {
        $date = date("Y-m-d", strtotime("-$i day"));
        $sightings_series[] = ["date" => $date, "count" => $sightingsByDate[$date] ?? 0];
    }

    // ── Last 14 days: signups per day ──────────────────────────────────
    $signups_series = [];
    $res = q($conn,
        "SELECT DATE(created_at) AS d, COUNT(*) AS c
         FROM users
         WHERE created_at >= (CURDATE() - INTERVAL 13 DAY)
         GROUP BY DATE(created_at)"
    );
    $signupsByDate = [];
    while ($row = $res->fetch_assoc()) {
        $signupsByDate[$row["d"]] = (int) $row["c"];
    }
    for ($i = 13; $i >= 0; $i--) {
        $date = date("Y-m-d", strtotime("-$i day"));
        $signups_series[] = ["date" => $date, "count" => $signupsByDate[$date] ?? 0];
    }

    // ── Verification status breakdown (replaces the old "source" breakdown,
    //    since flower_pins has no source column — every row is a real sighting) ─
    $verified_count = (int) q($conn,
        "SELECT COUNT(*) AS c FROM flower_pins WHERE upvotes > downvotes"
    )->fetch_assoc()["c"];

    $flagged_count = $flagged_pins; // upvotes < downvotes, already computed above

    $neutral_count = (int) q($conn,
        "SELECT COUNT(*) AS c FROM flower_pins WHERE upvotes = downvotes"
    )->fetch_assoc()["c"];

    $status_breakdown = [
        ["status" => "verified", "count" => $verified_count],
        ["status" => "flagged", "count" => $flagged_count],
        ["status" => "neutral", "count" => $neutral_count],
    ];

    // ── Species (pollinator) breakdown — parsed from each pin's `name` column,
    //    which holds the AI-identified species (e.g. "🦋 Monarch Butterfly").
    //    ecosystem_data is NOT used here — it's just a fixed placeholder string
    //    ("AI Synthesized Sighting") set by the app, not per-species data.
    $species_counts = ["bee" => 0, "butterfly" => 0, "moth" => 0, "other" => 0];

    $res = q($conn,
        "SELECT name FROM flower_pins WHERE name IS NOT NULL AND name != ''"
    );
    while ($row = $res->fetch_assoc()) {
        $bucket = categorize_pollinator($row["name"]);
        if ($bucket !== null) {
            $species_counts[$bucket]++;
        }
    }

    $species_breakdown = [];
    foreach ($species_counts as $species => $count) {
        $species_breakdown[] = ["species" => $species, "count" => $count];
    }

    echo json_encode([
        "status" => "success",
        "totals" => [
            "total_users" => $total_users,
            "new_users_this_month" => $new_users_this_month,
            "total_sightings" => $total_sightings,
            "sightings_today" => $sightings_today,
            "sightings_this_month" => $sightings_this_month,
            "flagged_pins" => $flagged_pins,
        ],
        "sightings_last_14_days" => $sightings_series,
        "signups_last_14_days" => $signups_series,
        "status_breakdown" => $status_breakdown,
        "species_breakdown" => $species_breakdown,
    ]);

    $conn->close();

} catch (Throwable $e) {
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
    exit;
}
