<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Content-Type: application/json");

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

    if (!isset($_GET['lat']) || !isset($_GET['lon'])) {
        throw new Exception("Missing lat/lon.");
    }

    $lat = (float)$_GET['lat'];
    $lon = (float)$_GET['lon'];
    // Default 25km radius; allow override via ?radius_km= if ever needed.
    $radius_km = isset($_GET['radius_km']) ? (float)$_GET['radius_km'] : 25;

    // Haversine distance in SQL: filters flower_pins to those within radius_km
    // of the given point, ordered nearest first.
    $query = "
        SELECT
            id,
            name,
            latitude,
            longitude,
            (
                6371 * ACOS(
                    COS(RADIANS(?)) * COS(RADIANS(latitude)) *
                    COS(RADIANS(longitude) - RADIANS(?)) +
                    SIN(RADIANS(?)) * SIN(RADIANS(latitude))
                )
            ) AS distance_km
        FROM flower_pins
        HAVING distance_km <= ?
        ORDER BY distance_km ASC
    ";

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new Exception("SQL compilation error: " . $conn->error);
    }
    $stmt->bind_param("dddd", $lat, $lon, $lat, $radius_km);
    $stmt->execute();
    $result = $stmt->get_result();

    $seen = [];      // species name (lowercased, deduped) -> count
    $pinCount = 0;

    while ($row = $result->fetch_assoc()) {
        $pinCount++;
        // Pin names are saved like "🦋 Western Honeybee" from PollinatorAnalyzer.
        // Strip leading emoji/symbols and surrounding whitespace to get a clean species label.
        $clean = preg_replace('/^[^\p{L}\p{N}]+/u', '', trim($row['name']));
        $clean = trim($clean);
        if ($clean === '') continue;

        $key = mb_strtolower($clean);
        if (!isset($seen[$key])) {
            $seen[$key] = ["label" => $clean, "count" => 0];
        }
        $seen[$key]["count"]++;
    }
    $stmt->close();

    $species = array_values($seen);
    // Most-frequently-spotted species first.
    usort($species, function ($a, $b) {
        return $b["count"] <=> $a["count"];
    });

    echo json_encode([
        "status" => "success",
        "radius_km" => $radius_km,
        "total_pins_in_radius" => $pinCount,
        "species" => $species
    ]);

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
