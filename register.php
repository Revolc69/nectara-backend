
<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

try {
    require_once __DIR__ . "/db.php";

    $data = json_decode(file_get_contents("php://input"));

    if (!empty($data->email) && !empty($data->password)) {
        $email = trim($data->email);
        $password = trim($data->password);

        $checkQuery = "SELECT id FROM users WHERE email = :email LIMIT 1";
        $checkStmt = $conn->prepare($checkQuery);
        $checkStmt->bindParam(":email", $email);
        $checkStmt->execute();

        if ($checkStmt->fetch(PDO::FETCH_ASSOC)) {
            echo json_encode([
                "status" => "error",
                "message" => "Email already registered."
            ]);
            exit();
        }

        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

        $query = "INSERT INTO users (email, password) VALUES (:email, :password)";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(":email", $email);
        $stmt->bindParam(":password", $hashedPassword);

        if ($stmt->execute()) {
            echo json_encode([
                "status" => "success",
                "message" => "Account successfully created."
            ]);
        } else {
            echo json_encode([
                "status" => "error",
                "message" => "Server error."
            ]);
        }
    } else {
        echo json_encode([
            "status" => "error",
            "message" => "Incomplete data."
        ]);
    }
} catch (PDOException $exception) {
    error_log("Registration database error: " . $exception->getMessage());
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "A database error occurred."
    ]);
    exit();
}
?>
