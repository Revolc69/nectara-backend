
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

        $query = "SELECT id, email, password, user_type,
                  UNIX_TIMESTAMP(deactivated_at) AS deactivated_ts
                  FROM users WHERE email = :email LIMIT 1";

        $stmt = $conn->prepare($query);
        $stmt->bindParam(":email", $email);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            if (password_verify($password, $row['password'])) {
                $deleteAtTs = null;

                if ($row['deactivated_ts'] !== null) {
                    $deleteAtTs = (int)$row['deactivated_ts'] + 15 * 86400;

                    if (time() >= $deleteAtTs) {
                        echo json_encode([
                            "status" => "error",
                            "message" => "This account was deactivated and is scheduled for permanent deletion."
                        ]);
                        exit();
                    }
                }

                $emailParts = explode('@', $row['email']);
                $generatedUsername = $emailParts[0];

                echo json_encode([
                    "status" => "success",
                    "message" => "Login successful.",
                    "user_id" => (int)$row['id'],
                    "email" => $row['email'],
                    "username" => $generatedUsername,
                    "user_type" => $row['user_type'] ?? "regular",
                    "delete_at_ts" => $deleteAtTs
                ]);
                exit();
            }

            echo json_encode([
                "status" => "error",
                "message" => "Invalid password."
            ]);
            exit();
        }

        echo json_encode([
            "status" => "error",
            "message" => "No account tied to that email was found."
        ]);
        exit();
    }

    echo json_encode([
        "status" => "error",
        "message" => "Incomplete request credentials supplied."
    ]);

} catch (PDOException $exception) {
    error_log("Login database error: " . $exception->getMessage());
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "A database error occurred."
    ]);
    exit();
}
?>
