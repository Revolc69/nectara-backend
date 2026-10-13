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

try {
    $conn = new PDO("mysql:host=$host;dbname=$db_name", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $data = json_decode(file_get_contents("php://input"));
    $action = $data->action ?? null;

    // ── Step 1: confirm the email exists before letting the user proceed ──
    if ($action === "verify_email") {
        if (empty($data->email)) {
            http_response_code(200);
            echo json_encode(["status" => "error", "message" => "Email is required."]);
            exit();
        }

        $email = trim($data->email);
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
        $stmt->bindParam(":email", $email);
        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            http_response_code(200);
            echo json_encode(["status" => "success", "message" => "Email found."]);
        } else {
            http_response_code(200);
            echo json_encode(["status" => "error", "message" => "No account tied to that email was found."]);
        }
        exit();
    }

    // ── Step 2: set the new password for that email ──
    if ($action === "reset_password") {
        if (empty($data->email) || empty($data->new_password)) {
            http_response_code(200);
            echo json_encode(["status" => "error", "message" => "Email and new password are required."]);
            exit();
        }

        $email = trim($data->email);
        $newPassword = trim($data->new_password);

        // Same password rules enforced on the frontend for registration/login
        if (strlen($newPassword) < 8) {
            http_response_code(200);
            echo json_encode(["status" => "error", "message" => "Password must be at least 8 characters long."]);
            exit();
        }
        if (!preg_match('/[A-Z]/', $newPassword)) {
            http_response_code(200);
            echo json_encode(["status" => "error", "message" => "Password must include at least one uppercase letter."]);
            exit();
        }

        $checkStmt = $conn->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
        $checkStmt->bindParam(":email", $email);
        $checkStmt->execute();

        if ($checkStmt->rowCount() === 0) {
            http_response_code(200);
            echo json_encode(["status" => "error", "message" => "No account tied to that email was found."]);
            exit();
        }

        $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
        $updateStmt = $conn->prepare("UPDATE users SET password = :password WHERE email = :email");
        $updateStmt->bindParam(":password", $hashedPassword);
        $updateStmt->bindParam(":email", $email);

        if ($updateStmt->execute()) {
            http_response_code(200);
            echo json_encode(["status" => "success", "message" => "Password updated successfully."]);
        } else {
            http_response_code(200);
            echo json_encode(["status" => "error", "message" => "Could not update password."]);
        }
        exit();
    }

    http_response_code(200);
    echo json_encode(["status" => "error", "message" => "Unknown or missing action."]);

} catch (PDOException $exception) {
    http_response_code(200);
    echo json_encode(["status" => "error", "message" => "Database error: " . $exception->getMessage()]);
    exit();
}
?>
