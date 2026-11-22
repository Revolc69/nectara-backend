
<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    // Use the shared Aiven database connection
    require_once __DIR__ . "/db.php";

    $data = json_decode(file_get_contents("php://input"));
    $action = $data->action ?? null;
    error_log("Forgot password action: " . ($action ?? "NULL"));
    // Step 1: Verify that the email exists
    if ($action === "verify_email") {
        if (empty($data->email)) {
            echo json_encode([
                "status" => "error",
                "message" => "Email is required."
            ]);
            exit();
        }

        $email = trim($data->email);

        $stmt = $conn->prepare(
            "SELECT id FROM users WHERE email = :email LIMIT 1"
        );
        $stmt->execute([":email" => $email]);

        if ($stmt->fetch(PDO::FETCH_ASSOC)) {
            echo json_encode([
                "status" => "success",
                "message" => "Email found."
            ]);
        } else {
            echo json_encode([
                "status" => "error",
                "message" => "No account tied to that email was found."
            ]);
        }

        exit();
    }

    // Step 2: Reset the password
    if ($action === "reset_password") {
        if (
            empty($data->email) ||
            !isset($data->new_password) ||
            $data->new_password === ""
        ) {
            echo json_encode([
                "status" => "error",
                "message" => "Email and new password are required."
            ]);
            exit();
        }

        $email = trim($data->email);
        $newPassword = $data->new_password;

        if (strlen($newPassword) < 8) {
            echo json_encode([
                "status" => "error",
                "message" => "Password must be at least 8 characters long."
            ]);
            exit();
        }

        if (!preg_match('/[A-Z]/', $newPassword)) {
            echo json_encode([
                "status" => "error",
                "message" => "Password must include at least one uppercase letter."
            ]);
            exit();
        }

        $checkStmt = $conn->prepare(
            "SELECT id FROM users WHERE email = :email LIMIT 1"
        );
        $checkStmt->execute([":email" => $email]);

        if (!$checkStmt->fetch(PDO::FETCH_ASSOC)) {
            echo json_encode([
                "status" => "error",
                "message" => "No account tied to that email was found."
            ]);
            exit();
        }

        $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);

        $updateStmt = $conn->prepare(
            "UPDATE users SET password = :password WHERE email = :email"
        );
        $updateStmt->execute([
            ":password" => $hashedPassword,
            ":email" => $email
        ]);

        echo json_encode([
            "status" => "success",
            "message" => "Password updated successfully."
        ]);
        exit();
    }

    echo json_encode([
        "status" => "error",
        "message" => "Unknown or missing action."
    ]);

} catch (PDOException $exception) {
    error_log("Forgot password database error: " . $exception->getMessage());
    http_response_code(500);

    echo json_encode([
        "status" => "error",
        "message" => "A database error occurred."
    ]);
}
?>
