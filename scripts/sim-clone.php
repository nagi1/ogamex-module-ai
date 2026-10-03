<?php

/**
 * Copy the running cohort's database into a simulation database.
 *
 * DEV TOOLING: reads the database the container is configured for and writes `$argv[1]` (default
 * `ogamex-sim`), dropping it first. Pure SQL through PDO, so it needs no mysql client in the container.
 * The copy is what `ai:sim` is allowed to move into the future; the live cohort is never touched.
 *
 *   php Modules/AI/scripts/sim-clone.php ogamex-sim
 */

$source = (string) getenv('DB_DATABASE');
$target = $argv[1] ?? 'ogamex-sim';

if ($source === '' || $source === $target || !str_contains(strtolower($target), 'sim')) {
    fwrite(STDERR, "usage: DB_DATABASE=<cohort db> php sim-clone.php <target containing \"sim\">\n");

    exit(2);
}

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s', getenv('DB_HOST'), getenv('DB_PORT') ?: '3306'),
    (string) getenv('DB_USERNAME'),
    (string) getenv('DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);

$started = microtime(true);
$pdo->exec("DROP DATABASE IF EXISTS `{$target}`");
$pdo->exec("CREATE DATABASE `{$target}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
$pdo->exec('SET UNIQUE_CHECKS = 0');

$tables = $pdo->query(
    "SELECT table_name FROM information_schema.tables WHERE table_schema = '{$source}' AND table_type = 'BASE TABLE'"
)->fetchAll(PDO::FETCH_COLUMN);

foreach ($tables as $table) {
    $pdo->exec("CREATE TABLE `{$target}`.`{$table}` LIKE `{$source}`.`{$table}`");
    $pdo->exec("INSERT INTO `{$target}`.`{$table}` SELECT * FROM `{$source}`.`{$table}`");
}

$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
printf("cloned %d table(s) %s -> %s in %.1f s\n", count($tables), $source, $target, microtime(true) - $started);
