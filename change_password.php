<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

$host = "localhost";
$db_name = "nectara_db";
$username = "root";
$password = "";

// // 2. Database connection configurations
// $host = "sql305.infinityfree.com";
// $db_name = "if0_42089936_nectaradb";
// $username = "if0_42089936";
// $password = "ma3XFwTmS5";

try {
    $conn = new PDO("mysql:host=$host;dbname=$db_name", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $data = json_decode(file_get_contents("php://input"));

    if (empty($data->user_id) || empty($data->current_password) || empty($data->new_password)) {
        http_response_code(200);
        echo json_encode(["status" => "error", "message" => "Incomplete request data."]);
        exit();
    }

    $userId = (int)$data->user_id;
    $currentPassword = trim($data->current_password);
    $newPassword = trim($data->new_password);

    if (strlen($newPassword) < 8) {
        http_response_code(200);
        echo json_encode(["status" => "error", "message" => "New password must be at least 8 characters."]);
        exit();
    }

    $query = "SELECT password FROM users WHERE id = :id LIMIT 1";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(":id", $userId);
    $stmt->execute();

    if ($stmt->rowCount() === 0) {
        http_response_code(200);
        echo json_encode(["status" => "error", "message" => "Account not found."]);
        exit();
    }

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!password_verify($currentPassword, $row['password'])) {
        http_response_code(200);
        echo json_encode(["status" => "error", "message" => "Current password is incorrect."]);
        exit();
    }

    $hashedNewPassword = password_hash($newPassword, PASSWORD_BCRYPT);
    $updateQuery = "UPDATE users SET password = :password WHERE id = :id";
    $updateStmt = $conn->prepare($updateQuery);
    $updateStmt->bindParam(":password", $hashedNewPassword);
    $updateStmt->bindParam(":id", $userId);

    if ($updateStmt->execute()) {
        http_response_code(200);
        echo json_encode(["status" => "success", "message" => "Password updated."]);
    } else {
        http_response_code(200);
        echo json_encode(["status" => "error", "message" => "Could not update password."]);
    }
} catch (PDOException $exception) {
    http_response_code(200);
    echo json_encode(["status" => "error", "message" => "Database error: " . $exception->getMessage()]);
    exit();
}
?>
