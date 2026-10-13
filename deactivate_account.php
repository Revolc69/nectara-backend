<?php
// Starts the 15-day grace period. Put in C:\xampp\htdocs\nectara-backend\
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, ngrok-skip-browser-warning");
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

// ⚠ Replace with the same connection your other scripts use.
$conn = new mysqli("localhost", "root", "", "nectara_db");
if ($conn->connect_error) { echo json_encode(["status" => "error", "message" => "Database error"]); exit; }

const GRACE_DAYS = 15;
$in  = json_decode(file_get_contents("php://input"), true);
$uid = (int)($in['user_id'] ?? 0);
if ($uid <= 0) { echo json_encode(["status" => "error", "message" => "Missing user"]); exit; }

// Only starts the clock once: deactivating an already-deactivated account keeps the original date.
$stmt = $conn->prepare("UPDATE users SET deactivated_at = NOW() WHERE id = ? AND deactivated_at IS NULL");
$stmt->bind_param("i", $uid);
$stmt->execute();

$stmt = $conn->prepare("SELECT UNIX_TIMESTAMP(deactivated_at) + ? * 86400 AS delete_at_ts FROM users WHERE id = ?");
$days = GRACE_DAYS;
$stmt->bind_param("ii", $days, $uid);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

if (!$row || $row['delete_at_ts'] === null) { echo json_encode(["status" => "error", "message" => "Account not found"]); exit; }
echo json_encode(["status" => "success", "delete_at_ts" => (int)$row['delete_at_ts']]);
