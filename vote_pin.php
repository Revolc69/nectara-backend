<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// Connect to your nectara_db
$conn = new mysqli("localhost", "root", "", "nectara_db");

if ($conn->connect_error) {
    die(json_encode(["status" => "error", "message" => "Database Connection Failed"]));
}

$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data['id']) || !isset($data['type'])) {
    echo json_encode(["status" => "error", "message" => "Missing required parameters."]);
    exit();
}

$pinId = intval($data['id']);
$voteType = $data['type']; 

// Target your flower_pins table
if ($voteType === "upvote") {
    $query = "UPDATE flower_pins SET upvotes = upvotes + 1 WHERE id = ?";
} elseif ($voteType === "downvote") {
    $query = "UPDATE flower_pins SET downvotes = downvotes + 1 WHERE id = ?";
} else {
    echo json_encode(["status" => "error", "message" => "Invalid vote type."]);
    exit();
}

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $pinId);

if ($stmt->execute()) {
    echo json_encode(["status" => "success", "message" => "Vote counted!"]);
} else {
    echo json_encode(["status" => "error", "message" => "Failed to update vote."]);
}

$stmt->close();
$conn->close();
?>