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

    if (!empty($data->email) && !empty($data->password)) {
        $email = trim($data->email);
        $password = trim($data->password);

        $checkQuery = "SELECT id FROM users WHERE email = :email LIMIT 1";
        $checkStmt = $conn->prepare($checkQuery);
        $checkStmt->bindParam(":email", $email);
        $checkStmt->execute();

        if ($checkStmt->rowCount() > 0) {
            echo json_encode(["status" => "error", "message" => "Email already registered."]);
            exit();
        }

        $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
        $query = "INSERT INTO users (email, password) VALUES (:email, :password)";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(":email", $email);
        $stmt->bindParam(":password", $hashedPassword);

        if ($stmt->execute()) {
            echo json_encode(["status" => "success", "message" => "Account successfully created."]);
        } else {
            echo json_encode(["status" => "error", "message" => "Server error."]);
        }
    } else {
        echo json_encode(["status" => "error", "message" => "Incomplete data."]);
    }
} catch (PDOException $exception) {
    // Any DB error now returns proper JSON instead of a raw PHP error page.
    echo json_encode(["status" => "error", "message" => "Database error: " . $exception->getMessage()]);
    exit();
}
?>