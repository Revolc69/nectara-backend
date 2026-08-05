<?php
/**
 * add_user_type_column.php  —  run ONCE, then delete this file.
 * Visit: http://192.168.1.98/nectara-backend/add_user_type_column.php
 *
 * Also creates pin_comments.user_id column if it is missing,
 * and adds the upgraded_at column to users.
 */

header("Content-Type: application/json");

$conn = new mysqli("localhost", "root", "", "nectara_db");
if ($conn->connect_error) {
    echo json_encode(["status" => "error", "message" => $conn->connect_error]);
    exit;
}

$results = [];

$queries = [
    // Add user_type to users (safe — IF NOT EXISTS equivalent via IGNORE)
    "ALTER TABLE users ADD COLUMN user_type ENUM('regular','expert') NOT NULL DEFAULT 'regular'",
    // Add upgraded_at timestamp
    "ALTER TABLE users ADD COLUMN upgraded_at DATETIME DEFAULT NULL",
    // Ensure pin_comments has a user_id column (our new add_comment.php requires it)
    "ALTER TABLE pin_comments ADD COLUMN user_id INT DEFAULT NULL",
    // Index for fast look-ups
    "ALTER TABLE pin_comments ADD INDEX idx_user_id (user_id)",
];

foreach ($queries as $sql) {
    $ok = $conn->query($sql);
    // 1060 = duplicate column (already exists) — treat as OK
    $results[] = [
        "sql"    => substr($sql, 0, 60) . "…",
        "status" => ($ok || $conn->errno === 1060 || $conn->errno === 1061) ? "OK" : "ERROR",
        "error"  => (!$ok && $conn->errno !== 1060 && $conn->errno !== 1061) ? $conn->error : null,
    ];
}

echo json_encode(["migration" => $results], JSON_PRETTY_PRINT);
$conn->close();
?>