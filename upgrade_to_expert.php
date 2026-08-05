<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

mysqli_report(MYSQLI_REPORT_OFF);
ini_set('display_errors', 0);

try {
    $conn = new mysqli("localhost", "root", "", "nectara_db");
    if ($conn->connect_error) throw new Exception("DB connection failed: " . $conn->connect_error);

    // Accept JSON body
    $data    = json_decode(file_get_contents("php://input"), true);
    $user_id = isset($data['user_id']) ? (int)$data['user_id'] : 0;

    if (!$user_id) throw new Exception("Missing user_id.");

    // ── Thresholds ─────────────────────────────────────────────────────────
    $SIGHTING_THRESHOLD = 50;
    $STREAK_THRESHOLD   = 100;

    // ── Check current user_type ────────────────────────────────────────────
    $stmt = $conn->prepare("SELECT user_type FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id); $stmt->execute();
    $userRow = $stmt->get_result()->fetch_assoc(); $stmt->close();

    if (!$userRow) throw new Exception("User not found.");
    if ($userRow['user_type'] === 'expert') {
        echo json_encode(["status" => "already_expert", "message" => "You are already an Expert."]);
        exit;
    }

    // ── Verify sightings ──────────────────────────────────────────────────
    $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM flower_pins WHERE user_id = ?");
    $stmt->bind_param("i", $user_id); $stmt->execute();
    $total_sightings = (int)$stmt->get_result()->fetch_assoc()['cnt']; $stmt->close();

    // ── Verify streak (from nectara_login_log, same table as get_achievements) ──
    $stmt = $conn->prepare("SELECT login_date FROM nectara_login_log WHERE user_id = ? ORDER BY login_date DESC");
    $stmt->bind_param("i", $user_id); $stmt->execute();
    $result = $stmt->get_result(); $stmt->close();
    $dates = []; while ($r = $result->fetch_assoc()) $dates[] = $r['login_date'];

    $longest_streak = 0; $run = 0;
    for ($i = 0; $i < count($dates); $i++) {
        if ($i === 0) { $run = 1; } else {
            $gap = (int)(new DateTime($dates[$i-1]))->diff(new DateTime($dates[$i]))->format('%a');
            $run = ($gap === 1) ? $run + 1 : 1;
        }
        if ($run > $longest_streak) $longest_streak = $run;
    }

    // ── Re-check eligibility server-side ──────────────────────────────────
    if ($total_sightings < $SIGHTING_THRESHOLD || $longest_streak < $STREAK_THRESHOLD) {
        echo json_encode([
            "status"  => "not_eligible",
            "message" => "You need {$SIGHTING_THRESHOLD} sightings and a {$STREAK_THRESHOLD}-day streak.",
            "total_sightings" => $total_sightings,
            "longest_streak"  => $longest_streak,
        ]);
        exit;
    }

    // ── Upgrade ──────────────────────────────────────────────────────────
    $stmt = $conn->prepare("UPDATE users SET user_type = 'expert', upgraded_at = NOW() WHERE id = ?");
    $stmt->bind_param("i", $user_id); $stmt->execute(); $stmt->close();

    echo json_encode([
        "status"      => "success",
        "message"     => "You are now a Nectara Expert!",
        "upgraded_at" => date("Y-m-d H:i:s"),
    ]);

    $conn->close();

} catch (Exception $e) {
    if (ob_get_length()) ob_clean();
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    exit;
}
?>