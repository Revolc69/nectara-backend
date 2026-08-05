<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$conn = new mysqli("localhost", "root", "", "nectara_db");
if ($conn->connect_error) {
    echo json_encode(["status" => "error", "message" => "DB connection failed"]);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);

$pin_id       = isset($data['pin_id'])       ? (int)$data['pin_id']           : 0;
$user_id      = isset($data['user_id'])      ? (int)$data['user_id']          : 0;
$comment_text = isset($data['comment_text']) ? trim($data['comment_text'])     : '';

if (!$pin_id || !$user_id || $comment_text === '') {
    echo json_encode(["status" => "error", "message" => "Missing required fields."]);
    exit;
}

if (strlen($comment_text) > 1000) {
    echo json_encode(["status" => "error", "message" => "Comment too long (max 1000 characters)."]);
    exit;
}

// ── Server-side expert check — cannot be bypassed from the app ────────────────
$stmt = $conn->prepare("SELECT user_type FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$userRow = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$userRow) {
    echo json_encode(["status" => "error", "message" => "User not found."]);
    exit;
}
if ($userRow['user_type'] !== 'expert') {
    echo json_encode([
        "status"  => "forbidden",
        "message" => "Only Expert users can post verification comments.",
    ]);
    exit;
}

// ── Insert ────────────────────────────────────────────────────────────────────
$stmt = $conn->prepare(
    "INSERT INTO pin_comments (pin_id, user_id, comment_text) VALUES (?, ?, ?)"
);
$stmt->bind_param("iis", $pin_id, $user_id, $comment_text);

if ($stmt->execute()) {
    echo json_encode([
        "status"     => "success",
        "message"    => "Comment added!",
        "comment_id" => (int)$conn->insert_id,
    ]);
} else {
    echo json_encode(["status" => "error", "message" => $conn->error]);
}

$stmt->close();
$conn->close();
?>