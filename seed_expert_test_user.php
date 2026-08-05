<?php
/**
 * seed_expert_test_user.php  —  run ONCE in your browser, then delete it.
 *
 * Creates (or reuses) a test account, then inserts enough flower_pins and
 * nectara_login_log rows to clear BOTH expert thresholds:
 *   - 50+ sightings (flower_pins)
 *   - 100+ day streak (nectara_login_log, consecutive days)
 *
 * USAGE
 * -----
 * Seed a brand-new dedicated test account:
 *   http://192.168.1.98/nectara-backend/seed_expert_test_user.php
 *
 * Seed an EXISTING account you already use in the app (so you can just look
 * at Account/Achievements/Map with your normal login):
 *   http://192.168.1.98/nectara-backend/seed_expert_test_user.php?user_id=3
 *
 * This script introspects your actual flower_pins / nectara_login_log
 * column list via INFORMATION_SCHEMA and fills any other required
 * (NOT NULL, no default) columns with sensible placeholder values based on
 * their data type — so it won't break if your schema has extra columns
 * beyond what get_achievements.php reads.
 */

header("Content-Type: application/json");

$conn = new mysqli("localhost", "root", "", "nectara_db");
if ($conn->connect_error) {
    echo json_encode(["status" => "error", "message" => $conn->connect_error]);
    exit;
}

$DB_NAME = "nectara_db";
$SIGHTING_TARGET = 55; // buffer over the 50 threshold
$STREAK_TARGET_DAYS = 105; // buffer over the 100 threshold

// ── Step 1: find or create the target user ──────────────────────────────────
$result = ["steps" => []];

if (isset($_GET['user_id']) && trim($_GET['user_id']) !== '') {
    $userId = (int)$_GET['user_id'];
    $stmt = $conn->prepare("SELECT id, email FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        echo json_encode(["status" => "error", "message" => "No user with id $userId found."]);
        exit;
    }
    $result["steps"][] = "Using existing user_id=$userId ($row[email]).";
} else {
    $testEmail = "experttest@nectara.local";
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->bind_param("s", $testEmail);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        $userId = (int)$row['id'];
        $result["steps"][] = "Reusing existing test account (id=$userId).";
    } else {
        $hashed = password_hash("Test1234!", PASSWORD_BCRYPT);
        $stmt = $conn->prepare("INSERT INTO users (email, password) VALUES (?, ?)");
        $stmt->bind_param("ss", $testEmail, $hashed);
        $stmt->execute();
        $userId = (int)$conn->insert_id;
        $stmt->close();
        $result["steps"][] = "Created new test account: $testEmail / Test1234! (id=$userId).";
    }
    $result["login_email"] = $testEmail;
    $result["login_password"] = "Test1234!";
}

$result["user_id"] = $userId;

// ── Helper: build a placeholder value for a column based on its type ───────
function placeholderFor(string $type, string $columnName) {
    $type = strtolower($type);
    $col = strtolower($columnName);

    if (str_contains($col, 'lat')) return 14.5995 + (mt_rand(-500, 500) / 10000); // Manila-ish
    if (str_contains($col, 'lng') || str_contains($col, 'lon')) return 120.9842 + (mt_rand(-500, 500) / 10000);

    if (str_starts_with($type, 'enum(')) {
        // Grab the first quoted value inside enum(...)
        if (preg_match("/enum\('([^']+)'/i", $type, $m)) return $m[1];
        return '';
    }
    if (str_contains($type, 'int')) return 1;
    if (str_contains($type, 'decimal') || str_contains($type, 'float') || str_contains($type, 'double')) return 0.0;
    if (str_contains($type, 'date') || str_contains($type, 'time')) return date('Y-m-d H:i:s');
    return 'Seeded test data';
}

// ── Helper: get columns needing a value on INSERT (skip auto_increment) ────
function getInsertableColumns(mysqli $conn, string $dbName, string $table): array {
    $stmt = $conn->prepare(
        "SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
         ORDER BY ORDINAL_POSITION"
    );
    $stmt->bind_param("ss", $dbName, $table);
    $stmt->execute();
    $res = $stmt->get_result();
    $cols = [];
    while ($r = $res->fetch_assoc()) $cols[] = $r;
    $stmt->close();
    return $cols;
}

// ── Step 2: seed flower_pins ─────────────────────────────────────────────────
$stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM flower_pins WHERE user_id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$existingSightings = (int)$stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

$toInsert = max(0, $SIGHTING_TARGET - $existingSightings);
$pinCols = getInsertableColumns($conn, $DB_NAME, "flower_pins");

if ($toInsert > 0 && count($pinCols) > 0) {
    $insertCols = [];
    foreach ($pinCols as $c) {
        if (stripos($c['EXTRA'], 'auto_increment') !== false) continue; // skip PK
        $insertCols[] = $c;
    }

    $colNames = array_map(fn($c) => "`{$c['COLUMN_NAME']}`", $insertCols);
    $placeholders = implode(",", array_fill(0, count($insertCols), "?"));
    $sql = "INSERT INTO flower_pins (" . implode(",", $colNames) . ") VALUES (" . $placeholders . ")";
    $stmt = $conn->prepare($sql);

    if ($stmt === false) {
        $result["steps"][] = "flower_pins insert prepare failed: " . $conn->error;
    } else {
        $types = "";
        foreach ($insertCols as $c) {
            $types .= (str_contains(strtolower($c['DATA_TYPE']), 'int') ? "i"
                     : (in_array(strtolower($c['DATA_TYPE']), ['decimal','float','double']) ? "d" : "s"));
        }

        $inserted = 0;
        for ($i = 0; $i < $toInsert; $i++) {
            $values = [];
            foreach ($insertCols as $c) {
                $name = $c['COLUMN_NAME'];
                if (strtolower($name) === 'user_id') { $values[] = $userId; continue; }
                $values[] = placeholderFor($c['COLUMN_TYPE'] ?: $c['DATA_TYPE'], $name);
            }
            $stmt->bind_param($types, ...$values);
            if ($stmt->execute()) $inserted++;
        }
        $stmt->close();
        $result["steps"][] = "Inserted $inserted flower_pins rows (had $existingSightings, target $SIGHTING_TARGET).";
    }
} else {
    $result["steps"][] = "flower_pins: already has $existingSightings (target $SIGHTING_TARGET) — nothing to insert.";
}

// ── Step 3: seed nectara_login_log (consecutive days ending today) ─────────
$logCols = getInsertableColumns($conn, $DB_NAME, "nectara_login_log");

if (count($logCols) > 0) {
    $insertCols = [];
    foreach ($logCols as $c) {
        if (stripos($c['EXTRA'], 'auto_increment') !== false) continue;
        $insertCols[] = $c;
    }
    $colNames = array_map(fn($c) => "`{$c['COLUMN_NAME']}`", $insertCols);
    $placeholders = implode(",", array_fill(0, count($insertCols), "?"));
    // INSERT IGNORE so re-running this script (or days you already logged in) won't error on duplicates
    $sql = "INSERT IGNORE INTO nectara_login_log (" . implode(",", $colNames) . ") VALUES (" . $placeholders . ")";
    $stmt = $conn->prepare($sql);

    if ($stmt === false) {
        $result["steps"][] = "nectara_login_log insert prepare failed: " . $conn->error;
    } else {
        $types = "";
        foreach ($insertCols as $c) {
            $types .= (str_contains(strtolower($c['DATA_TYPE']), 'int') ? "i" : "s");
        }

        $inserted = 0;
        for ($d = 0; $d < $STREAK_TARGET_DAYS; $d++) {
            $day = date('Y-m-d', strtotime("-$d days"));
            $values = [];
            foreach ($insertCols as $c) {
                $name = strtolower($c['COLUMN_NAME']);
                if ($name === 'user_id') { $values[] = $userId; continue; }
                if (str_contains($name, 'login_date') || ($name === 'date')) { $values[] = $day; continue; }
                $values[] = placeholderFor($c['COLUMN_TYPE'] ?: $c['DATA_TYPE'], $c['COLUMN_NAME']);
            }
            $stmt->bind_param($types, ...$values);
            if ($stmt->execute()) $inserted++;
        }
        $stmt->close();
        $result["steps"][] = "Inserted $inserted nectara_login_log rows covering the last $STREAK_TARGET_DAYS consecutive days.";
    }
} else {
    $result["steps"][] = "Could not find nectara_login_log columns — check the table name.";
}

$result["status"] = "OK";
$result["next_step"] = "Check http://192.168.1.98/nectara-backend/get_achievements.php?user_id=$userId — expert_eligible should now be true. Then log in as this user and tap the upgrade banner in Account.";

echo json_encode($result, JSON_PRETTY_PRINT);
$conn->close();
?>