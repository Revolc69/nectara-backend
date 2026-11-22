```php
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
    // Connect to Aiven through the shared database configuration
    require_once __DIR__ . "/db.php";

    $data = json_decode(file_get_contents("php://input"));

    if (
        empty($data->user_id) ||
        !isset($data->current_password) ||
        $data->current_password === "" ||
        !isset($data->new_password) ||
        $data->new_password === ""
    ) {
        echo json_encode([
            "status" => "error",
            "message" => "Incomplete request data."
        ]);
        exit();
    }

    $userId = (int) $data->user_id;
    $currentPassword = $data->current_password;
    $newPassword = $data->new_password;

    if (strlen($newPassword) < 8) {
        echo json_encode([
            "status" => "error",
            "message" => "New password must be at least 8 characters."
        ]);
        exit();
    }

    $query = "SELECT password FROM users WHERE id = :id LIMIT 1";
    $stmt = $conn->prepare($query);
    $stmt->execute([":id" => $userId]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode([
            "status" => "error",
            "message" => "Account not found."
        ]);
        exit();
    }

    if (!password_verify($currentPassword, $row["password"])) {
        echo json_encode([
            "status" => "error",
            "message" => "Current password is incorrect."
        ]);
        exit();
    }

    $hashedNewPassword = password_hash($newPassword, PASSWORD_BCRYPT);

    $updateQuery = "UPDATE users SET password = :password WHERE id = :id";
    $updateStmt = $conn->prepare($updateQuery);
    $updateStmt->execute([
        ":password" => $hashedNewPassword,
        ":id" => $userId
    ]);

    echo json_encode([
        "status" => "success",
        "message" => "Password updated."
    ]);

} catch (PDOException $exception) {
    error_log("Change password database error: " . $exception->getMessage());
    http_response_code(500);

    echo json_encode([
        "status" => "error",
        "message" => "A database error occurred."
    ]);
}
?>
```