<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

/**
 * Releases test database sessions that a killed test run left behind.
 *
 * `docker compose exec` does not forward a signal to the process it started, so
 * cancelling or timing out a run leaves the workers alive inside the container. A
 * worker that is blocked between statements keeps its transaction open, and those row
 * locks outlive the run: the next run then fails every test on a one second lock wait
 * timeout, which looks like a hang but is really one stale lock.
 *
 * Only sessions that have been idle inside an open transaction past the grace period
 * are killed, so a healthy run's workers are never touched. Idle pooled connections
 * without a transaction hold no locks and are left alone.
 *
 *   php Modules/AI/scripts/reap-test-sessions.php [--grace=60]
 */

$root = dirname(__DIR__, 3);

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$grace = 60;

foreach ($argv as $argument) {
    if (preg_match('/^--grace=(\d+)$/', $argument, $matches) === 1) {
        $grace = (int) $matches[1];
    }
}

$database = (string) DB::connection()->getDatabaseName();

// A long idle transaction on a live database is legitimate, so this only ever runs
// against an obvious test database.
if (!str_contains($database, 'test')) {
    fwrite(STDERR, "Refusing to reap: {$database} does not look like a test database.\n");

    exit(1);
}

// The worker clones and the schema template are prefixed with the configured database
// name, so one pattern covers the base database and everything derived from it.
$sessions = DB::select(
    'SELECT processlist.id, processlist.db, processlist.time
       FROM information_schema.processlist AS processlist
       INNER JOIN information_schema.innodb_trx AS trx
               ON trx.trx_mysql_thread_id = processlist.id
      WHERE processlist.id <> CONNECTION_ID()
        AND trx.trx_state = ?
        AND processlist.command = ?
        AND processlist.time >= ?
        AND (processlist.db = ? OR processlist.db LIKE ?)',
    ['RUNNING', 'Sleep', $grace, $database, $database.'\\_%']
);

if ($sessions === []) {
    fwrite(STDOUT, "No stale test sessions to reap.\n");

    exit(0);
}

$reaped = 0;

foreach ($sessions as $session) {
    $id = (int) $session->id;

    try {
        DB::statement("KILL {$id}");
    } catch (Throwable $exception) {
        // The session can disappear between the listing and the kill.
        fwrite(STDERR, "Could not kill session {$id}: {$exception->getMessage()}\n");

        continue;
    }

    $reaped++;

    fwrite(STDOUT, "Reaped session {$id} on {$session->db} (idle {$session->time}s in an open transaction).\n");
}

fwrite(STDOUT, "Reaped {$reaped} stale test session(s).\n");
