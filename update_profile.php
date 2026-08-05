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

    if (empty($data->user_id) || empty($data->email)) {
        http_response_code(200);
        echo json_encode(["status" => "error", "message" => "Incomplete request data."]);
        exit();
    }

    $userId = (int)$data->user_id;
    $newEmail = trim($data->email);

    if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        http_response_code(200);
        echo json_encode(["status" => "error", "message" => "That email address doesn't look valid."]);
        exit();
    }

    // Make sure no OTHER user already has this email
    $checkQuery = "SELECT id FROM users WHERE email = :email AND id != :id LIMIT 1";
    $checkStmt = $conn->prepare($checkQuery);
    $checkStmt->bindParam(":email", $newEmail);
    $checkStmt->bindParam(":id", $userId);
    $checkStmt->execute();

    if ($checkStmt->rowCount() > 0) {
        http_response_code(200);
        echo json_encode(["status" => "error", "message" => "That email is already in use by another account."]);
        exit();
    }

    $updateQuery = "UPDATE users SET email = :email WHERE id = :id";
    $updateStmt = $conn->prepare($updateQuery);
    $updateStmt->bindParam(":email", $newEmail);
    $updateStmt->bindParam(":id", $userId);

    if ($updateStmt->execute()) {
        // Keep the auto-generated "username" behavior consistent with login.php
        $emailParts = explode('@', $newEmail);
        $generatedUsername = $emailParts[0];

        http_response_code(200);
        echo json_encode([
            "status" => "success",
            "message" => "Profile updated.",
            "email" => $newEmail,
            "username" => $generatedUsername,
        ]);
    } else {
        http_response_code(200);
        echo json_encode(["status" => "error", "message" => "Could not update profile."]);
    }
} catch (PDOException $exception) {
    http_response_code(200);
    echo json_encode(["status" => "error", "message" => "Database error: " . $exception->getMessage()]);
    exit();
}
?>
