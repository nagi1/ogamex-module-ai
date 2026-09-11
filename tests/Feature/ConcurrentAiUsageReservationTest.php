<?php

use Modules\AI\Enums\AiUsageBudgetScope;
use Modules\AI\Models\AiUsageBudget;
use Modules\AI\Models\AiUsageReservation;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ConcurrentAiUsageReservationTestCase extends TestCase
{
    protected string $conversationKey;

    protected int $playerId;

    protected string $universeScope;

    protected function setUp(): void
    {
        parent::setUp();

        $suffix = bin2hex(random_bytes(8));
        $this->universeScope = 'concurrent-universe-' . $suffix;
        $this->conversationKey = 'concurrent-conversation-' . $suffix;
        $this->playerId = random_int(1_000_000, 9_999_999);
    }

    protected function tearDown(): void
    {
        AiUsageReservation::query()->where('universe_scope', $this->universeScope)->delete();
        AiUsageBudget::query()
            ->where('reserved_for', '2026-09-11')
            ->where(function ($query): void {
                $query->where(function ($scopeQuery): void {
                    $scopeQuery->where('scope', AiUsageBudgetScope::Universe)
                        ->where('scope_key', $this->universeScope);
                })->orWhere(function ($scopeQuery): void {
                    $scopeQuery->where('scope', AiUsageBudgetScope::Player)
                        ->where('scope_key', (string) $this->playerId);
                })->orWhere(function ($scopeQuery): void {
                    $scopeQuery->where('scope', AiUsageBudgetScope::Conversation)
                        ->where('scope_key', $this->conversationKey);
                });
            })
            ->delete();

        parent::tearDown();
    }
}

uses(ConcurrentAiUsageReservationTestCase::class);

test('concurrent reservations cannot exceed a shared conversation cap', function (): void {
    $barrierPath = sys_get_temp_dir() . '/ai_usage_reservation_' . bin2hex(random_bytes(8));
    mkdir($barrierPath);
    $firstRequestKey = 'concurrent-request-a';
    $secondRequestKey = 'concurrent-request-b';
    $first = aiUsageReservationProcess($barrierPath, $this->universeScope, $this->playerId, $this->conversationKey, $firstRequestKey);
    $second = aiUsageReservationProcess($barrierPath, $this->universeScope, $this->playerId, $this->conversationKey, $secondRequestKey);

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
        ->and($outputs)->toBe(['rejected', 'reserved'])
        ->and(AiUsageReservation::query()->where('universe_scope', $this->universeScope)->count())->toBe(1)
        ->and(AiUsageBudget::query()
            ->where('scope', AiUsageBudgetScope::Conversation)
            ->where('scope_key', $this->conversationKey)
            ->sole()
            ->reserved_attempts)->toBe(1);

    unlink($barrierPath . '/' . $firstRequestKey);
    unlink($barrierPath . '/' . $secondRequestKey);
    unlink($barrierPath . '/release');
    rmdir($barrierPath);
});

function aiUsageReservationProcess(string $barrierPath, string $universeScope, int $playerId, string $conversationKey, string $requestKey): Process
{
    return new Process(
        ['php', '-r', aiUsageReservationProcessScript()],
        '/var/www',
        [
            'APP_ENV' => 'testing',
            'CACHE_DRIVER' => 'array',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'BROADCAST_CONNECTION' => 'log',
            'AI_USAGE_BARRIER_PATH' => $barrierPath,
            'AI_USAGE_UNIVERSE_SCOPE' => $universeScope,
            'AI_USAGE_PLAYER_ID' => (string) $playerId,
            'AI_USAGE_CONVERSATION_KEY' => $conversationKey,
            'AI_USAGE_REQUEST_KEY' => $requestKey,
        ],
    );
}

function aiUsageReservationProcessScript(): string
{
    return <<<'PHP'
require 'vendor/autoload.php';

$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$barrierPath = (string) getenv('AI_USAGE_BARRIER_PATH');
$requestKey = (string) getenv('AI_USAGE_REQUEST_KEY');
file_put_contents($barrierPath . '/' . $requestKey, 'ready');

while (!is_file($barrierPath . '/release')) {
    usleep(1_000);
}

$request = new Modules\AI\Domain\Conversation\UsageReservationRequest(
    (string) getenv('AI_USAGE_UNIVERSE_SCOPE'),
    (int) getenv('AI_USAGE_PLAYER_ID'),
    (string) getenv('AI_USAGE_CONVERSATION_KEY'),
    $requestKey,
    10,
    10,
    Carbon\CarbonImmutable::parse('2026-09-11 10:00:00 UTC'),
);
$limit = new Modules\AI\Domain\Conversation\UsageBudgetLimit(1, 10, 10);
$limits = new Modules\AI\Domain\Conversation\UsageBudgetLimits($limit, $limit, $limit);
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

    throw new RuntimeException('Reservation processes did not reach the concurrency barrier.');
}
