<?php
// Shared by get_achievements.php and get_leaderboard.php.
// Put this file in the SAME folder as those two scripts.

/** True if $table has a column named $column. */
function table_has_column(mysqli $conn, string $table, string $column): bool {
    $t = $conn->real_escape_string($table);
    $c = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `$t` LIKE '$c'");
    return $res && $res->num_rows > 0;
}

/**
 * First date (Y-m-d) on which a run of consecutive login days reached each
 * milestone. $dates_asc must be oldest -> newest.
 * Returns [ milestone => 'Y-m-d' ] for the milestones that were ever reached.
 */
function streak_reach_dates(array $dates_asc, array $milestones): array {
    $reached = [];
    $run = 0;
    $prev = null;
    foreach ($dates_asc as $d) {
        if ($prev === null) {
            $run = 1;
        } else {
            $gap = (int)(new DateTime($prev))->diff(new DateTime($d))->format('%a');
            if ($gap === 0) { continue; }           // same day twice, ignore
            $run = ($gap === 1) ? $run + 1 : 1;
        }
        foreach ($milestones as $m) {
            if ($run >= $m && !isset($reached[$m])) { $reached[$m] = $d; }
        }
        $prev = $d;
    }
    return $reached;
}

/**
 * Pins per user in the order they were created (id ASC = chronological).
 * Returns [ user_id => [ ['id' => int, 'at' => 'Y-m-d H:i:s'|null], ... ] ].
 * 'at' is null when flower_pins has no created_at column, or for old rows that
 * were created before the column existed.
 */
function fetch_pins_by_user(mysqli $conn, bool $has_created_at, ?int $only_user = null): array {
    $col   = $has_created_at ? 'created_at' : 'NULL';
    $where = "user_id IS NOT NULL" . ($only_user !== null ? " AND user_id = " . (int)$only_user : "");
    $res = $conn->query("SELECT id, user_id, $col AS at FROM flower_pins WHERE $where ORDER BY user_id, id ASC");
    $out = [];
    while ($row = $res->fetch_assoc()) {
        $out[(int)$row['user_id']][] = ['id' => (int)$row['id'], 'at' => $row['at']];
    }
    return $out;
}

/** The latest of several timestamps, or null if ANY is unknown (can't be sure). */
function latest_known_time(array $times): ?string {
    if (empty($times)) return null;
    $best = null; $bestTs = -1;
    foreach ($times as $t) {
        if ($t === null || $t === '') return null;
        $ts = strtotime($t);
        if ($ts === false) return null;
        if ($ts > $bestTs) { $bestTs = $ts; $best = $t; }
    }
    return $best;
}

/**
 * Ranks leaderboard rows. Each row: user_id, username, value,
 * achieved_at (string|null), order_key (int|null; lower = reached it earlier).
 *
 * Order: higher value first; on a tie, whoever reached it FIRST; then the
 * lower order_key; then user_id only as a last, stable resort.
 * Ranks are distinct for real tie-breaks. People only share a rank when they
 * are still level on everything (or all have 0 and nothing to separate them).
 */
function build_ranked_entries(array $rows, int $current_user_id): array {
    $key = function (array $r): array {
        $ts = (!empty($r['achieved_at'])) ? strtotime($r['achieved_at']) : false;
        return [
            $ts === false ? PHP_INT_MAX : $ts,
            $r['order_key'] === null ? PHP_INT_MAX : (int)$r['order_key'],
        ];
    };

    usort($rows, function ($a, $b) use ($key) {
        if ($a['value'] !== $b['value']) return $b['value'] <=> $a['value'];
        [$ta, $oa] = $key($a);
        [$tb, $ob] = $key($b);
        if ($ta !== $tb) return $ta <=> $tb;
        if ($oa !== $ob) return $oa <=> $ob;
        return $a['user_id'] <=> $b['user_id'];
    });

    $entries = [];
    $rank = 0;
    $prev = null;
    foreach ($rows as $i => $r) {
        $position = $i + 1;
        $k = $key($r);
        $level = $prev !== null
            && $r['value'] === $prev['value']
            && ($r['value'] === 0 || $k === $prev['k']);
        if (!$level) { $rank = $position; }
        $prev = ['value' => $r['value'], 'k' => $k];

        $entries[] = [
            "user_id"         => $r['user_id'],
            "username"        => $r['username'],
            "value"           => $r['value'],
            "rank"            => $rank,
            "achieved_at"     => $r['achieved_at'] ?: null,
            "is_current_user" => $r['user_id'] === $current_user_id,
        ];
    }
    return $entries;
}