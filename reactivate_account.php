<?php
// Cancels deactivation if the 15 days haven't run out. Put in C:\xampp\htdocs\nectara-backend\
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, ngrok-skip-browser-warning");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

// ⚠ Replace with the same connection your other scripts use.
$conn = new mysqli("localhost", "root", "", "nectara_db");
if ($conn->connect_error) { echo json_encode(["status" => "error", "message" => "Database error"]); exit; }

$in  = json_decode(file_get_contents("php://input"), true);
$uid = (int)($in['user_id'] ?? 0);
if ($uid <= 0) { echo json_encode(["status" => "error", "message" => "Missing user"]); exit; }

$stmt = $conn->prepare("UPDATE users SET deactivated_at = NULL
                        WHERE id = ? AND deactivated_at IS NOT NULL
                          AND deactivated_at > (NOW() - INTERVAL 15 DAY)");
$stmt->bind_param("i", $uid);
$stmt->execute();

if ($stmt->affected_rows > 0) {
  echo json_encode(["status" => "success"]);
} else {
  echo json_encode(["status" => "error", "message" => "This account can't be reactivated (it isn't deactivated, or the 15 days have passed)."]);
}
