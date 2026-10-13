<?php
// Permanently deletes accounts deactivated more than 15 days ago.
// Run once a day from Windows Task Scheduler (command-line only, not from a browser):
//   C:\xampp\php\php.exe C:\xampp\htdocs\nectara-backend\purge_deactivated.php
if (php_sapi_name() !== 'cli') { http_response_code(403); exit("CLI only"); }

// ⚠ Replace with the same connection your other scripts use.
$conn = new mysqli("localhost", "root", "", "nectara_db");
mysqli_report(MYSQLI_REPORT_OFF);

$due = $conn->query("SELECT id FROM users WHERE deactivated_at IS NOT NULL AND deactivated_at <= (NOW() - INTERVAL 15 DAY)");
while ($u = $due->fetch_assoc()) {
  $id = (int)$u['id'];
  $conn->begin_transaction();
  $steps = [
    // comments on this user's pins, then everything the user owns
    "DELETE FROM pin_comments WHERE pin_id IN (SELECT id FROM flower_pins WHERE user_id = $id)",
    "DELETE FROM pin_comments WHERE user_id = $id",
    "DELETE FROM flower_pins WHERE user_id = $id",
    "DELETE FROM user_gardens WHERE user_id = $id",
    "DELETE FROM nectara_login_log WHERE user_id = $id",
    "DELETE FROM users WHERE id = $id",
  ];
  $ok = true;
  foreach ($steps as $sql) {
    if (!$conn->query($sql)) { $ok = false; echo "FAILED for user $id: $sql -> {$conn->error}\n"; break; }
  }
  if ($ok) { $conn->commit(); echo "Deleted user $id\n"; } else { $conn->rollback(); }
}
