
<?php
header("Content-Type: text/plain; charset=UTF-8");

try {
    require_once __DIR__ . "/db.php";

    echo "SUCCESS: PHP connected to Aiven!";
    echo "\nDatabase: " . $conn->query("SELECT DATABASE()")->fetchColumn();

    echo "\nUsers: " . $conn->query("SELECT COUNT(*) FROM users")->fetchColumn();
    echo "\nFlower pins: " . $conn->query("SELECT COUNT(*) FROM flower_pins")->fetchColumn();

} catch (Throwable $e) {
    http_response_code(500);
    echo "FAILED: " . $e->getMessage();
}
?>
