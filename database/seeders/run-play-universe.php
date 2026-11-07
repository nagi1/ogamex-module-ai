<?php

// Runs PlayUniverseSeeder inside the application (scripts/play-universe.sh reseed):
//   php Modules/AI/database/seeders/run-play-universe.php HUMAN_NAME HUMAN_EMAIL

use Illuminate\Contracts\Console\Kernel;
use Modules\AI\Database\Seeders\PlayUniverseSeeder;

$root = dirname(__DIR__, 4);

require $root . '/vendor/autoload.php';
require __DIR__ . '/PlayUniverseSeeder.php';

$app = require $root . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$seeder = new PlayUniverseSeeder($argv[1] ?? 'Orion', $argv[2] ?? 'orion@play.ogamex.test');
$seeder->run();

foreach ($seeder->credentials as $role => $line) {
    echo strtoupper($role), ': ', $line, PHP_EOL;
}
foreach ($seeder->accounts as $account) {
    echo 'AI ', $account['player_id'], ' ', $account['persona'], ' (', $account['archetype'], ') ', $account['coords'], PHP_EOL;
}
