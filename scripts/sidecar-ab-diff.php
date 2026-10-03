<?php

/**
 * Per-session diff of two simulation databases: decision traces (selected action, reason, score components)
 * and the work the sessions queued (kind, player, payload). Floats within 1e-9 are equal.
 *
 *   DB_DATABASE=<any> php scripts/sidecar-ab-diff.php <baseline db> <other db>
 */

[, $base, $other] = $argv + [null, null, null];
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s', getenv('DB_HOST'), getenv('DB_PORT') ?: '3306'),
    (string) getenv('DB_USERNAME'),
    (string) getenv('DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

function same(mixed $a, mixed $b): bool
{
    if (is_numeric($a) && is_numeric($b)) {
        return abs((float) $a - (float) $b) < 1e-9;
    }
    if (is_array($a) && is_array($b)) {
        if (array_keys($a) !== array_keys($b)) {
            return false;
        }
        foreach ($a as $key => $value) {
            if (!same($value, $b[$key])) {
                return false;
            }
        }

        return true;
    }

    return $a === $b;
}

function rows(PDO $pdo, string $db, string $sql): array
{
    $keyed = [];
    $seen = [];
    foreach ($pdo->query(str_replace('{db}', "`{$db}`", $sql)) as $row) {
        $base = $row['player_id'] . '@' . $row['at'] . '#' . ($row['key'] ?? '');
        $n = $seen[$base] = ($seen[$base] ?? 0) + 1;
        $keyed[$base . '/' . $n] = $row;
    }

    return $keyed;
}

$queries = [
    'trace' => "SELECT player_id, observed_at AS at, input_hash AS `key`, selected_action, selected_reason, score_components FROM {db}.ai_decision_traces ORDER BY player_id, observed_at, id",
    'work' => "SELECT player_id, due_at AS at, idempotency_key AS `key`, kind, payload FROM {db}.ai_work_items WHERE state <> 1 ORDER BY player_id, due_at, id",
];

$total = 0;
$different = 0;
$first = [];
foreach ($queries as $name => $sql) {
    $a = rows($pdo, $base, $sql);
    $b = rows($pdo, $other, $sql);
    foreach ($a + $b as $key => $_) {
        $total++;
        $left = $a[$key] ?? null;
        $right = $b[$key] ?? null;
        $field = null;
        if ($left === null || $right === null) {
            $field = $left === null ? 'missing in baseline' : 'missing in other';
        }
        foreach ($left && $right ? array_keys($left) : [] as $column) {
            $l = json_decode((string) $left[$column], true) ?? $left[$column];
            $r = json_decode((string) $right[$column], true) ?? $right[$column];
            if (!same($l, $r)) {
                $field = $column;
                break;
            }
        }
        if ($field === null) {
            continue;
        }
        $different++;
        if (count($first) < 5) {
            $first[] = sprintf('%s %s field=%s before=%s after=%s', $name, $key, $field, substr(json_encode($left[$field] ?? null), 0, 120), substr(json_encode($right[$field] ?? null), 0, 120));
        }
    }
}

printf("AB-DIFF %s vs %s: %d decision/work rows compared, %d differ\n", $base, $other, $total, $different);
foreach ($first as $line) {
    echo "  $line\n";
}
