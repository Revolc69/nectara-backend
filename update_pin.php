<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$conn = new mysqli("localhost", "root", "", "nectara_db");

if ($conn->connect_error) {
    echo json_encode(["status" => "error", "message" => "DB connection failed: " . $conn->connect_error]);
    exit;
}

$id   = isset($_POST["id"]) ? (int)$_POST["id"] : 0;
$name = isset($_POST["name"]) ? trim($_POST["name"]) : "";

if ($id <= 0 || empty($name)) {
    echo json_encode([
        "status" => "error", 
        "message" => "Invalid target parameters for alteration. Received ID: " . $id . ", Name: " . $name
    ]);
    exit;
}

// Prepared statement safely binds variables automatically
$stmt = $conn->prepare("UPDATE flower_pins SET name = ? WHERE id = ?");
$stmt->bind_param("si", $name, $id);

if ($stmt->execute()) {
    echo json_encode(["status" => "success", "message" => "Pin layout updated successfully!"]);
} else {
    echo json_encode(["status" => "error", "message" => "SQL Update failed: " . $stmt->error]);
}

$stmt->close();
$conn->close();
?>