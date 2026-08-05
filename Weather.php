<?php
session_start();

// 🔒 SECURITY CHECKPOINT: Kick user back to signin if no valid session exists
if (!isset($_SESSION['user_id'])) {
    header("Location: signin.php");
    exit();
}

$apiKey = "8986b3760b198277048aa3f47cf0b6de";

// Default location falls back to Bacolod if no city parameter is passed
$city = isset($_GET['city']) ? $_GET['city'] : "Bacolod";

try {
    // STEP 1: Get coordinates using Geocoding API
    $geoUrl = "http://api.openweathermap.org/geo/1.0/direct?q=" . urlencode($city) . "&limit=1&appid=" . $apiKey;
    $geoResponse = @file_get_contents($geoUrl);
    
    if ($geoResponse === FALSE) {
        throw new Exception("Unable to reach Geocoding API.");
    }
    
    $geoData = json_decode($geoResponse, true);

    if (empty($geoData)) {
        $locationName = "Unknown Location";
        $weatherDataFound = false;
    } else {
        $lat = $geoData[0]['lat'];
        $lon = $geoData[0]['lon'];
        $locationName = $geoData[0]['name'];

        // STEP 2: Get weather data
        $weatherUrl = "https://api.openweathermap.org/data/2.5/weather?lat=$lat&lon=$lon&appid=$apiKey&units=metric";
        $weatherResponse = @file_get_contents($weatherUrl);
        
        if ($weatherResponse === FALSE) {
            throw new Exception("Unable to reach Weather API.");
        }
        
        $data = json_decode($weatherResponse, true);
        $weatherDataFound = true;
    }
} catch (Exception $e) {
    $api_error = $e->getMessage();
    $weatherDataFound = false;
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Nectara - Habitat Weather Dashboard</title>
    <style>
        body { font-family: Arial, sans-serif; background-color: #eef2f5; padding: 4px; }
        .dashboard { max-width: 600px; margin: 30px auto; background: white; padding: 25px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        .nav-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #ddd; padding-bottom: 10px; margin-bottom: 20px; }
        .logout-btn { color: #d9534f; text-decoration: none; font-weight: bold; }
        input[type="text"] { padding: 10px; width: 70%; border: 1px solid #ccc; border-radius: 4px; }
        button { padding: 10px 15px; background-color: #2ECC71; color: white; border: none; border-radius: 4px; cursor: pointer; }
        button:hover { background-color: #27ae60; }
        .weather-info { margin-top: 20px; background: #f9f9f9; padding: 15px; border-radius: 6px; }
    </style>
</head>
<body>

<div class="dashboard">
    <div class="nav-header">
        <span>Logged in as: <b><?php echo htmlspecialchars($_SESSION['email']); ?></b></span>
        <a href="logout.php" class="logout-btn">Logout</a>
    </div>

    <h3>Search Ecosystem Conditions</h3>
    <form method="GET" action="weather.php">
        <input type="text" name="city" placeholder="Enter location (e.g., Bacolod)" required>
        <button type="submit">Get Weather</button>
    </form>

    <hr style="border: 0; border-top: 1px solid #eee; margin: 20px 0;">

    <?php if ($weatherDataFound): ?>
        <h2>Ecosystem Conditions in <?php echo htmlspecialchars($locationName); ?></h2>
        
        <div class="weather-info">
            <p><b>Condition:</b> <?php echo ucfirst(htmlspecialchars($data['weather'][0]['description'])); ?></p>
            <p><b>Temperature:</b> <?php echo htmlspecialchars($data['main']['temp']); ?> °C</p>
            <p><b>Humidity:</b> <?php echo htmlspecialchars($data['main']['humidity']); ?>%</p>
            <p><b>Wind Speed:</b> <?php echo htmlspecialchars($data['wind']['speed']); ?> m/s</p>
        </div>
    <?php else: ?>
        <p style="color: red;">
            <?php echo isset($api_error) ? htmlspecialchars($api_error) : "Location layout details not found. Please try another city entry."; ?>
        </p>
    <?php endif; ?>
</div>

</body>
</html>