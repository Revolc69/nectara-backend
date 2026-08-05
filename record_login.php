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

    // Accept either JSON body or form-encoded POST, same fallback pattern as save_pin.php
    if (empty($_POST)) {
        $raw_json = file_get_contents('php://input');
        $decoded = json_decode($raw_json, true);
        if (is_array($decoded)) {
            $_POST = $decoded;
        }
    }

    if (!isset($_POST['user_id']) || trim($_POST['user_id']) === '') {
        throw new Exception("Missing user_id.");
    }

    $user_id = (int)$_POST['user_id'];

    // Record today's login. UNIQUE KEY (user_id, login_date) makes this safe
    // to call every time the app opens -- duplicate same-day calls are silently ignored.
    $today = date('Y-m-d');
    $stmt = $conn->prepare("INSERT IGNORE INTO nectara_login_log (user_id, login_date) VALUES (?, ?)");
    if (!$stmt) {
        throw new Exception("SQL compilation error: " . $conn->error);
    }
    $stmt->bind_param("is", $user_id, $today);
    if (!$stmt->execute()) {
        throw new Exception("SQL execution error: " . $stmt->error);
    }
    $stmt->close();

    // Pull every login date for this user, most recent first, to compute streaks.
    $stmt = $conn->prepare("SELECT login_date FROM nectara_login_log WHERE user_id = ? ORDER BY login_date DESC");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $dates = [];
    while ($row = $result->fetch_assoc()) {
        $dates[] = $row['login_date'];
    }
    $stmt->close();

    $total_login_days = count($dates);

    // ── Current streak: consecutive days counting back from today/yesterday ──
    $current_streak = 0;
    if ($total_login_days > 0) {
        $cursor = new DateTime($dates[0]);
        $now = new DateTime($today);
        $diffFromToday = (int)$now->diff($cursor)->format('%a');

        // Most recent login must be today or yesterday for an active streak;
        // otherwise the streak was already broken before this check.
        if ($diffFromToday <= 1) {
            $current_streak = 1;
            for ($i = 1; $i < $total_login_days; $i++) {
                $prev = new DateTime($dates[$i - 1]);
                $curr = new DateTime($dates[$i]);
                $gap = (int)$prev->diff($curr)->format('%a');
                if ($gap === 1) {
                    $current_streak++;
                } else {
                    break;
                }
            }
        }
    }

    // ── Longest streak ever, scanning the full history ──
    $longest_streak = 0;
    $run = 0;
    for ($i = 0; $i < $total_login_days; $i++) {
        if ($i === 0) {
            $run = 1;
        } else {
            $prev = new DateTime($dates[$i - 1]);
            $curr = new DateTime($dates[$i]);
            $gap = (int)$prev->diff($curr)->format('%a');
            $run = ($gap === 1) ? $run + 1 : 1;
        }
        if ($run > $longest_streak) {
            $longest_streak = $run;
        }
    }

    // ── Member since (days as a member) ──
    $stmt = $conn->prepare("SELECT created_at FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $userRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $member_since = $userRow['created_at'] ?? null;
    $days_as_member = 0;
    if ($member_since) {
        $createdDate = new DateTime($member_since);
        $nowDate = new DateTime($today);
        $days_as_member = (int)$createdDate->diff($nowDate)->format('%a') + 1; // inclusive of day 1
    }

    echo json_encode([
        "status" => "success",
        "current_streak" => $current_streak,
        "longest_streak" => $longest_streak,
        "total_login_days" => $total_login_days,
        "member_since" => $member_since,
        "days_as_member" => $days_as_member
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