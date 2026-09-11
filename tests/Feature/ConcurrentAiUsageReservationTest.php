<?php

use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

test('concurrent reservations cannot exceed a shared conversation cap', function (): void {
    $suffix = bin2hex(random_bytes(8));
    $universeScope = 'concurrent-universe-' . $suffix;
    $conversationKey = 'concurrent-conversation-' . $suffix;
    $playerId = random_int(1_000_000, 9_999_999);
    $barrierPath = sys_get_temp_dir() . '/ai_usage_reservation_' . $suffix;
    mkdir($barrierPath);
    $firstRequestKey = 'concurrent-request-a';
    $secondRequestKey = 'concurrent-request-b';
    $seed = aiUsageReservationProcess($barrierPath, $universeScope, $playerId, $conversationKey, 'seed', 'seed');
    $seed->run();

    expect($seed->getExitCode())->toBe(0)
        ->and(trim($seed->getOutput()))->toBe('seeded');

    try {
        $first = aiUsageReservationProcess($barrierPath, $universeScope, $playerId, $conversationKey, $firstRequestKey);
        $second = aiUsageReservationProcess($barrierPath, $universeScope, $playerId, $conversationKey, $secondRequestKey);

        $first->start();
        $second->start();

        aiWaitForUsageReservationProcesses($barrierPath, $firstRequestKey, $secondRequestKey);
        touch($barrierPath . '/release');
        $first->wait();
        $second->wait();

        $outputs = [trim($first->getOutput()), trim($second->getOutput())];
        sort($outputs);

        expect($first->getExitCode())->toBe(0)
            ->and($second->getExitCode())->toBe(0)
            ->and($outputs)->toBe(['rejected', 'reserved']);
    } finally {
        $cleanup = aiUsageReservationProcess($barrierPath, $universeScope, $playerId, $conversationKey, 'cleanup', 'cleanup');
        $cleanup->run();
        aiDeleteUsageReservationBarrier($barrierPath, $firstRequestKey, $secondRequestKey);
    }
});

function aiDeleteUsageReservationBarrier(string $barrierPath, string $firstRequestKey, string $secondRequestKey): void
{
    foreach ([$firstRequestKey, $secondRequestKey, 'release'] as $entry) {
        $path = $barrierPath . '/' . $entry;

        if (!is_file($path)) {
            continue;
        }

        unlink($path);
    }

    if (is_dir($barrierPath)) {
        rmdir($barrierPath);
    }
}

function aiUsageReservationProcess(string $barrierPath, string $universeScope, int $playerId, string $conversationKey, string $requestKey, string $mode = 'reserve'): Process
{
    return app()->makeWith(Process::class, [
        'command' => ['php', '-r', aiUsageReservationProcessScript()],
        'cwd' => '/var/www',
        'env' => [
            'APP_ENV' => 'testing',
            'CACHE_DRIVER' => 'array',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'BROADCAST_CONNECTION' => 'log',
            'DB_DATABASE' => (string) config('database.connections.mysql.database'),
            'AI_USAGE_BARRIER_PATH' => $barrierPath,
            'AI_USAGE_UNIVERSE_SCOPE' => $universeScope,
            'AI_USAGE_PLAYER_ID' => (string) $playerId,
            'AI_USAGE_CONVERSATION_KEY' => $conversationKey,
            'AI_USAGE_REQUEST_KEY' => $requestKey,
            'AI_USAGE_MODE' => $mode,
        ],
    ]);
}

function aiUsageReservationProcessScript(): string
{
    return <<<'PHP'
require 'vendor/autoload.php';

$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$barrierPath = (string) getenv('AI_USAGE_BARRIER_PATH');
$requestKey = (string) getenv('AI_USAGE_REQUEST_KEY');
$mode = (string) getenv('AI_USAGE_MODE');

if ($mode === 'seed') {
    $reservedFor = Carbon\CarbonImmutable::parse('2026-09-11 10:00:00 UTC')->toDateString();
    $now = now();
    Modules\AI\Models\AiUsageBudget::query()->upsert([
        [
            'scope' => Modules\AI\Enums\AiUsageBudgetScope::Universe->value,
            'scope_key' => (string) getenv('AI_USAGE_UNIVERSE_SCOPE'),
            'reserved_for' => $reservedFor,
            'reserved_attempts' => 0,
            'reserved_input_tokens' => 0,
            'reserved_output_tokens' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'scope' => Modules\AI\Enums\AiUsageBudgetScope::Player->value,
            'scope_key' => (string) getenv('AI_USAGE_PLAYER_ID'),
            'reserved_for' => $reservedFor,
            'reserved_attempts' => 0,
            'reserved_input_tokens' => 0,
            'reserved_output_tokens' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'scope' => Modules\AI\Enums\AiUsageBudgetScope::Conversation->value,
            'scope_key' => (string) getenv('AI_USAGE_CONVERSATION_KEY'),
            'reserved_for' => $reservedFor,
            'reserved_attempts' => 0,
            'reserved_input_tokens' => 0,
            'reserved_output_tokens' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ], ['scope', 'scope_key', 'reserved_for'], ['updated_at']);

    echo 'seeded';

    exit;
}

if ($mode === 'cleanup') {
    Modules\AI\Models\AiUsageReservation::query()
        ->where('universe_scope', (string) getenv('AI_USAGE_UNIVERSE_SCOPE'))
        ->delete();
    Modules\AI\Models\AiUsageBudget::query()
        ->where('reserved_for', '2026-09-11')
        ->where(function ($query): void {
            $query->where(function ($scopeQuery): void {
                $scopeQuery->where('scope', Modules\AI\Enums\AiUsageBudgetScope::Universe->value)
                    ->where('scope_key', (string) getenv('AI_USAGE_UNIVERSE_SCOPE'));
            })->orWhere(function ($scopeQuery): void {
                $scopeQuery->where('scope', Modules\AI\Enums\AiUsageBudgetScope::Player->value)
                    ->where('scope_key', (string) getenv('AI_USAGE_PLAYER_ID'));
            })->orWhere(function ($scopeQuery): void {
                $scopeQuery->where('scope', Modules\AI\Enums\AiUsageBudgetScope::Conversation->value)
                    ->where('scope_key', (string) getenv('AI_USAGE_CONVERSATION_KEY'));
            });
        })
        ->delete();

    echo 'cleaned';

    exit;
}

file_put_contents($barrierPath . '/' . $requestKey, 'ready');

while (!is_file($barrierPath . '/release')) {
    usleep(1_000);
}

$request = app()->makeWith(Modules\AI\Domain\Conversation\UsageReservationRequest::class, [
    'universeScope' => (string) getenv('AI_USAGE_UNIVERSE_SCOPE'),
    'playerId' => (int) getenv('AI_USAGE_PLAYER_ID'),
    'conversationKey' => (string) getenv('AI_USAGE_CONVERSATION_KEY'),
    'requestKey' => $requestKey,
    'inputTokens' => 10,
    'outputTokens' => 10,
    'reservedAt' => Carbon\CarbonImmutable::parse('2026-09-11 10:00:00 UTC'),
]);
$limit = app()->makeWith(Modules\AI\Domain\Conversation\UsageBudgetLimit::class, [
    'attempts' => 1,
    'inputTokens' => 10,
    'outputTokens' => 10,
]);
$limits = app()->makeWith(Modules\AI\Domain\Conversation\UsageBudgetLimits::class, [
    'universe' => $limit,
    'player' => $limit,
    'conversation' => $limit,
]);
$reservation = app(Modules\AI\Actions\ReserveAiUsageAction::class)->handle($request, $limits);

echo $reservation === null ? 'rejected' : 'reserved';
PHP;
}

function aiWaitForUsageReservationProcesses(string $barrierPath, string $firstRequestKey, string $secondRequestKey): void
{
    for ($attempt = 0; $attempt < 200; $attempt++) {
        if (is_file($barrierPath . '/' . $firstRequestKey) && is_file($barrierPath . '/' . $secondRequestKey)) {
            return;
        }

        usleep(10_000);
    }

    throw app()->makeWith(RuntimeException::class, ['message' => 'Reservation processes did not reach the concurrency barrier.']);
}
