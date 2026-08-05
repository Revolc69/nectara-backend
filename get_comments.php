<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");

// Make sure ANY PHP error/warning becomes JSON, not raw HTML that breaks
// the app's JSON.parse(). This is what was silently killing the response.
set_exception_handler(function ($e) {
    http_response_code(200);
    header("Content-Type: application/json");
    echo json_encode([
        "status"  => "error",
        "message" => "Server error: " . $e->getMessage(),
    ]);
    exit;
});

try {
    $conn = new mysqli("localhost", "root", "", "nectara_db");
} catch (mysqli_sql_exception $e) {
    echo json_encode(["status" => "error", "message" => "DB connection failed: " . $e->getMessage()]);
    exit;
}

if ($conn->connect_error) {
    echo json_encode(["status" => "error", "message" => "DB connection failed: " . $conn->connect_error]);
    exit;
}

$pin_id = isset($_GET['pin_id']) ? (int)$_GET['pin_id'] : 0;

if ($pin_id === 0) {
    echo json_encode(["status" => "success", "comments" => []]);
    exit;
}

// Figure out, at runtime, which columns actually exist on `users`, so a
// missing `username`/`email` column can't blow up the whole request.
$userCols = [];
$colsResult = $conn->query("SHOW COLUMNS FROM users");
if ($colsResult) {
    while ($col = $colsResult->fetch_assoc()) {
        $userCols[] = $col['Field'];
    }
}
$hasUsername = in_array('username', $userCols, true);
$hasEmail    = in_array('email', $userCols, true);
$hasUserType = in_array('user_type', $userCols, true);

$usernameExpr = "'Expert'";
if ($hasUsername && $hasEmail) {
    $usernameExpr = "COALESCE(u.username, SUBSTRING_INDEX(u.email, '@', 1), 'Expert')";
} elseif ($hasUsername) {
    $usernameExpr = "COALESCE(u.username, 'Expert')";
} elseif ($hasEmail) {
    $usernameExpr = "COALESCE(SUBSTRING_INDEX(u.email, '@', 1), 'Expert')";
}
$userTypeExpr = $hasUserType ? "u.user_type" : "NULL";

// JOIN users so we can return username + user_type for the expert badge in the app
$sql = "
    SELECT
        pc.id,
        pc.comment_text,
        pc.created_at,
        $usernameExpr AS username,
        $userTypeExpr AS user_type
    FROM pin_comments pc
    LEFT JOIN users u ON u.id = pc.user_id
    WHERE pc.pin_id = ?
    ORDER BY pc.created_at ASC
";

$stmt = $conn->prepare($sql);
if ($stmt === false) {
    echo json_encode(["status" => "error", "message" => "Query prepare failed: " . $conn->error]);
    exit;
}

$stmt->bind_param("i", $pin_id);
$stmt->execute();
$result = $stmt->get_result();
$stmt->close();

$comments = [];
while ($row = $result->fetch_assoc()) {
    $comments[] = [
        "id"           => (int)$row["id"],
        "comment_text" => $row["comment_text"],
        "created_at"   => $row["created_at"],
        "username"     => $row["username"],
        "user_type"    => $row["user_type"] ?? "regular",
    ];
}

echo json_encode(["status" => "success", "comments" => $comments]);
$conn->close();
?>
