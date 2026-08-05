<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json");

// Suppress raw HTML errors from breaking the React Native JSON parser
mysqli_report(MYSQLI_REPORT_OFF);
ini_set('display_errors', 0);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $conn = new mysqli("localhost", "root", "", "nectara_db");

    if ($conn->connect_error) {
        throw new Exception("DB connection failed: " . $conn->connect_error);
    }

    // ✨ FAIL-SAFE DETECTOR: If $_POST is empty, decode raw incoming JSON payload input instead
    if (empty($_POST)) {
        $raw_json = file_get_contents('php://input');
        $decoded = json_decode($raw_json, true);
        if (is_array($decoded)) {
            $_POST = $decoded;
        }
    }

    // Verify geographical structures are completely filled
    if (!isset($_POST['latitude']) || !isset($_POST['longitude'])) {
        throw new Exception("Database Sync Warning: Missing core geographical marker fields.");
    }   

    if (!isset($_POST['user_id']) || trim($_POST['user_id']) === '') {
        throw new Exception("Sync Error: Active community account profile validation missing.");
    }

    $user_id = (int)$_POST['user_id']; 
    $name = isset($_POST['name']) ? trim($_POST['name']) : "Custom Flower Location";
    $latitude = (float)$_POST['latitude'];
    $longitude = (float)$_POST['longitude'];
    $description = isset($_POST['description']) ? trim($_POST['description']) : '';
    $ecosystem_data = isset($_POST['ecosystem_data']) ? trim($_POST['ecosystem_data']) : '';

    $img_url = ""; 

    // Process the base64 data stream if bundled in the form parameters
    if (!empty($_POST['image_base64'])) {
        $upload_dir = 'uploads/';
        
        if (!is_dir($upload_dir)) {
            if (!mkdir($upload_dir, 0777, true)) {
                throw new Exception("Failed to initialize system media folder path.");
            }
        }

        $base64_string = $_POST['image_base64'];
        
        if (preg_match('/^data:image\/(\w+);base64,/', $base64_string, $type)) {
            $base64_string = substr($base64_string, strpos($base64_string, ',') + 1);
        }

        $image_bytes = base64_decode($base64_string);

        if ($image_bytes !== false) {
            $new_file_name = uniqid('pin_', true) . '.jpg';
            $target_file = $upload_dir . $new_file_name;

            if (file_put_contents($target_file, $image_bytes)) {
                $img_url = $upload_dir . $new_file_name; 
            } else {
                throw new Exception("Failed to write decoded base64 stream to disk storage.");
            }
        } else {
            throw new Exception("Invalid base64 transmission structure data payload encountered.");
        }
    }

    // Prepare statement mapping rules
    $stmt = $conn->prepare("INSERT INTO flower_pins (user_id, name, latitude, longitude, description, ecosystem_data, upvotes, downvotes, img_url) VALUES (?, ?, ?, ?, ?, ?, 0, 0, ?)");
    
    if (!$stmt) {
        throw new Exception("SQL compilation breakdown: " . $conn->error);
    }

    $stmt->bind_param("isddsss", $user_id, $name, $latitude, $longitude, $description, $ecosystem_data, $img_url);

    if ($stmt->execute()) {
        echo json_encode([
            "status" => "success", 
            "message" => "Sighting stored and image uploaded successfully!",
            "pin_id" => (int)$conn->insert_id,
            "img_url" => $img_url
        ]);
    } else {
        throw new Exception("SQL execution error: " . $stmt->error);
    }

    $stmt->close();
    $conn->close();

} catch (Exception $e) {
    if (ob_get_length()) ob_clean();
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
    exit;
}
?> 