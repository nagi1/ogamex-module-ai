<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Symfony\Component\Process\Process;
use Tests\TestCase;

// Two processes recording the same pair at the same moment (fleet arrivals on two page loads) must leave one row
// with both changes counted: no duplicate key, no lost update. The writers are real processes released together
// at a barrier, since the race lives between connections. DatabaseTransactions selects the parallel worker's
// database, which the spawned processes inherit.
uses(TestCase::class, DatabaseTransactions::class);

test('two writers on the same pair leave one row with both interactions counted', function (): void {
    $suffix = bin2hex(random_bytes(8));
    $barrier = sys_get_temp_dir() . '/ai_relationship_race_' . $suffix;
    mkdir($barrier);
    $playerId = random_int(1_000_000_000, 1_999_999_999);
    $otherId = $playerId + 1;

    try {
        $writers = [aiRelationshipProcess($barrier, $playerId, $otherId, 'a'), aiRelationshipProcess($barrier, $playerId, $otherId, 'b')];
        foreach ($writers as $writer) {
            $writer->start();
        }

        $deadline = microtime(true) + 30;
        while ((!is_file($barrier . '/a') || !is_file($barrier . '/b')) && microtime(true) < $deadline) {
            usleep(1_000);
        }

        touch($barrier . '/release');
        foreach ($writers as $writer) {
            $writer->wait();
        }

        $read = aiRelationshipProcess($barrier, $playerId, $otherId, 'read');
        $read->run();

        expect($writers[0]->getExitCode())->toBe(0, $writers[0]->getErrorOutput() . $writers[0]->getOutput())
            ->and($writers[1]->getExitCode())->toBe(0, $writers[1]->getErrorOutput() . $writers[1]->getOutput())
            ->and(json_decode(trim($read->getOutput()), true))->toBe(['rows' => 1, 'threat' => '0.2000', 'revision' => 2]);
    } finally {
        aiRelationshipProcess($barrier, $playerId, $otherId, 'cleanup')->run();
        foreach (['a', 'b', 'release'] as $entry) {
            @unlink($barrier . '/' . $entry);
        }
        @rmdir($barrier);
    }
});

function aiRelationshipProcess(string $barrier, int $playerId, int $otherId, string $mode): Process
{
    return new Process(['php', '-r', <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$barrier = (string) getenv('AI_RACE_BARRIER');
$mode = (string) getenv('AI_RACE_MODE');
$pair = Modules\AI\Models\AiRelationship::query()->where('player_id', (int) getenv('AI_RACE_PLAYER'))->where('other_player_id', (int) getenv('AI_RACE_OTHER'));

if ($mode === 'read') {
    $row = (clone $pair)->first();
    echo json_encode(['rows' => (clone $pair)->count(), 'threat' => (string) $row?->threat, 'revision' => (int) $row?->revision]);
    exit;
}

if ($mode === 'cleanup') {
    $pair->delete();
    exit;
}

file_put_contents($barrier . '/' . $mode, 'ready');
while (!is_file($barrier . '/release')) {
    usleep(500);
}

app(Modules\AI\Actions\RecordAiRelationshipInteractionAction::class)->handle(
    (int) getenv('AI_RACE_PLAYER'), (int) getenv('AI_RACE_OTHER'), 1, Carbon\CarbonImmutable::parse('2026-11-07 12:00:00'), threatChange: 0.1,
);
PHP], base_path(), [
        'APP_ENV' => 'testing',
        'CACHE_STORE' => 'array',
        'CACHE_DRIVER' => 'array',
        'SESSION_DRIVER' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'BROADCAST_CONNECTION' => 'log',
        'DB_DATABASE' => (string) config('database.connections.mysql.database'),
        'AI_RACE_BARRIER' => $barrier,
        'AI_RACE_PLAYER' => (string) $playerId,
        'AI_RACE_OTHER' => (string) $otherId,
        'AI_RACE_MODE' => $mode,
    ], null, 60);
}
