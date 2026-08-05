<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

// Suppress raw HTML error outputs so they don't break React Native's JSON parser
mysqli_report(MYSQLI_REPORT_OFF); 

$conn = new mysqli("localhost", "root", "", "nectara_db");

if ($conn->connect_error) {
    die(json_encode(["status" => "error", "message" => "Connection failed"]));
}

// Image URLs are returned as relative paths (e.g. "uploads/pin_12.jpg").
// The frontend prepends whatever API_BASE is currently active (local IP,
// ngrok, or prod) — so this file never needs to know or care which network
// mode the client is using. Absolute URLs (e.g. external images) pass through as-is.

// ✨ FIXED: Swapped u.username for an email substring split, and changed INNER JOIN to LEFT JOIN
// ✨ FIXED: Added IFNULL to prevent empty user accounts from hiding pins
$query = "SELECT 
            fp.id, 
            fp.name, 
            fp.latitude, 
            fp.longitude, 
            fp.upvotes, 
            fp.downvotes, 
            fp.img_url, 
            fp.description, 
            fp.ecosystem_data,
            fp.user_id,
            IFNULL(SUBSTRING_INDEX(u.email, '@', 1), 'Nature Explorer') AS pinned_by 
          FROM flower_pins fp
          LEFT JOIN users u ON fp.user_id = u.id
          ORDER BY fp.id DESC";

$result = $conn->query($query);

$pins = array();

if ($result && $result->num_rows > 0) {
    while($row = $result->fetch_assoc()) {
        
        $final_image_url = null;
        if (!empty($row['img_url'])) {
            if (strpos($row['img_url'], 'http') === 0) {
                // Already an absolute URL — keep as-is
                $final_image_url = $row['img_url'];
            } elseif (strpos($row['img_url'], 'uploads/') === 0) {
                // Relative path — return unmodified; the frontend builds the
                // full URL using its own current API_BASE
                $final_image_url = $row['img_url'];
            } else {
                // Completely drops distorted test string placeholders so React Native ignores them safely
                $final_image_url = null;
            }
        }

        // Setup a safe fallback name if the pin was created anonymously or user was deleted
        $display_name = !empty($row['pinned_by']) ? $row['pinned_by'] : "Nature Explorer";

        $pins[] = array(
            "id" => (int)$row['id'],
            "name" => $row['name'],
            "latitude" => (float)$row['latitude'],
            "longitude" => (float)$row['longitude'],
            "upvotes" => intval($row['upvotes']),
            "downvotes" => intval($row['downvotes']),
            "img_url" => $final_image_url, 
            "description" => $row['description'],      
            "ecosystem_data" => $row['ecosystem_data'],
            "user_id" => $row['user_id'] !== null ? (int)$row['user_id'] : null, 
            "pinned_by" => $display_name
        );
    }
} else if (!$result) {
    // If the database query itself failed, send a clean JSON error back instead of breaking
    die(json_encode(["status" => "error", "message" => "Query failure: " . $conn->error]));
}

echo json_encode($pins);
$conn->close();
?>