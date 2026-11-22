<?php

// Optional: load a local .env file if present (never commit .env to Git)
$envFile = __DIR__ . "/.env";
if (is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === "" || str_starts_with($line, "#") || !str_contains($line, "=")) {
            continue;
        }
        [$key, $value] = explode("=", $line, 2);
        $key = trim($key);
        $value = trim($value, " \t\n\r\0\x0B\"'");
        if ($key !== "" && getenv($key) === false) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

function env_or_default(string $key, string $default = ""): string
{
    $val = getenv($key);
    return ($val !== false && $val !== "") ? $val : $default;
}

// Choose: "local" or "aiven" (defaults to "local" if unset)
$db_mode = env_or_default("DB_MODE", "local");

// LOCAL XAMPP DATABASE
if ($db_mode === "local") {
    $host = env_or_default("DB_HOST", "localhost");
    $db_name = env_or_default("DB_NAME", "nectara_db");
    $username = env_or_default("DB_USER", "root");
    $password = env_or_default("DB_PASS", "");

    $dsn = "mysql:host=$host;dbname=$db_name;charset=utf8mb4";
    $options = [];
}

// AIVEN CLOUD DATABASE
elseif ($db_mode === "aiven") {
    $host = env_or_default("DB_HOST");
    $port = env_or_default("DB_PORT", "22421");
    $db_name = env_or_default("DB_NAME", "defaultdb");
    $username = env_or_default("DB_USER", "avnadmin");
    $password = env_or_default("DB_PASS");

    if ($host === "" || $password === "") {
        http_response_code(500);
        exit(json_encode([
            "status" => "error",
            "message" => "Missing Aiven database configuration."
        ]));
    }

    $dsn = "mysql:host=$host;port=$port;dbname=$db_name;charset=utf8mb4";
    $options = [
        PDO::MYSQL_ATTR_SSL_CA => __DIR__ . "/aiven-ca.pem"
    ];
}

else {
    http_response_code(500);
    exit(json_encode([
        "status" => "error",
        "message" => "Invalid database mode."
    ]));
}

try {
    $conn = new PDO($dsn, $username, $password, $options);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    http_response_code(500);
    exit(json_encode([
        "status" => "error",
        "message" => "Database connection failed."
    ]));
}
?>