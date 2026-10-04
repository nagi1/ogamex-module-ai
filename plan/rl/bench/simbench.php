<?php
// Runs the module's own ai:sim with a query listener: counts SQL statements and DB time per phase.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$q = 0; $dbms = 0.0; $byTable = [];
Illuminate\Support\Facades\DB::listen(function ($e) use (&$q, &$dbms, &$byTable) {
    $q++; $dbms += $e->time;
    if (preg_match('/^\s*(select|insert|update|delete)\b.*?\b(?:from|into|update)\s+`?(\w+)`?/is', $e->sql, $m)) { $k = strtolower($m[1]).' '.$m[2]; $byTable[$k] = ($byTable[$k] ?? 0) + 1; }
});
$args = json_decode($argv[1] ?? '{}', true);
$t = microtime(true);
$out = new Symfony\Component\Console\Output\BufferedOutput(); $rc = Illuminate\Support\Facades\Artisan::call("ai:sim", $args + ["--force-db" => true], $out);
$wall = microtime(true) - $t;
echo $out->fetch();
arsort($byTable);
printf("QUERIES: %d total, %.1f s DB time (%.0f%% of %.1f s wall), peak mem %.0f MB\n", $q, $dbms/1000, $dbms/10/$wall, $wall, memory_get_peak_usage(true)/1e6);
foreach (array_slice($byTable, 0, 25, true) as $k => $n) printf("  %7d %s\n", $n, $k);
