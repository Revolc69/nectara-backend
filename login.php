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

    if (!empty($data->email) && !empty($data->password)) {
        $email = trim($data->email);
        $password = trim($data->password);

        // ✨ FIXED: Removed the non-existent 'username' column from the SELECT statement
        $query = "SELECT id, email, password, user_type, UNIX_TIMESTAMP(deactivated_at) AS deactivated_ts FROM users WHERE email = :email LIMIT 1";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(":email", $email);
        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (password_verify($password, $row['password'])) {
                http_response_code(200);

                // Deactivated accounts may still sign in during the 15-day grace period
                // so they can see the countdown and reactivate. After that they are blocked
                // (purge_deactivated.php removes them permanently).
                $deleteAtTs = null;
                if ($row['deactivated_ts'] !== null) {
                    $deleteAtTs = (int)$row['deactivated_ts'] + 15 * 86400;
                    if (time() >= $deleteAtTs) {
                        echo json_encode(["status" => "error", "message" => "This account was deactivated and is scheduled for permanent deletion."]);
                        exit();
                    }
                }

                // ✨ CLEVER FALLBACK: Split email at the '@' mark to generate a clean handle automatically
                // e.g., "nature_explorer@gmail.com" becomes "nature_explorer"
                $emailParts = explode('@', $row['email']);
                $generatedUsername = $emailParts[0];

                echo json_encode([
                    "status" => "success",
                    "message" => "Login successful.",
                    "user_id" => (int)$row['id'],
                    "email" => $row['email'],
                    "username" => $generatedUsername,
                    "user_type" => $row['user_type'] ?? "regular",
                    "delete_at_ts" => $deleteAtTs, // null = active; UNIX seconds = deactivated
                ]);
                exit();
            } else {
                http_response_code(200);
                echo json_encode(["status" => "error", "message" => "Invalid password configuration string."]);
                exit();
            }
        } else {
            http_response_code(200);
            echo json_encode(["status" => "error", "message" => "No account tied to that email was found."]);
            exit();
        }
    } else {
        http_response_code(200);
        echo json_encode(["status" => "error", "message" => "Incomplete request credentials supplied."]);
    }
} catch (PDOException $exception) {
    // Any DB error (missing column, bad query, connection drop mid-request, etc.)
    // now returns proper JSON instead of a raw PHP error page that breaks
    // JSON.parse() on the app side and shows as a misleading "Network Error".
    http_response_code(200);
    echo json_encode(["status" => "error", "message" => "Database error: " . $exception->getMessage()]);
    exit();
}
?>