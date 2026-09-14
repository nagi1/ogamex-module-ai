<?php

use Illuminate\Support\Facades\Log;
use Modules\AI\Contracts\LongTermMemory;
use Modules\AI\Domain\Conversation\NativeLongTermMemory;
use Modules\AI\Enums\AiMemoryDriver;
use Modules\AI\Support\AiClock;
use Modules\AI\Support\LongTermMemorySelector;
use Modules\AI\Support\SystemAiClock;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

beforeEach(function (): void {
    // The AgentOS arm builds a driver-scoped circuit breaker, which resolves the module clock.
    $this->app->bind(AiClock::class, SystemAiClock::class);
});

/**
 * Recall is a swap point, so its selection is configuration rather than a fixed
 * binding. The native implementation stays the fallback: an unrecognised driver name
 * is reported and ignored instead of silently removing an AI's memory of a player.
 */
test('native scoped recall remains the default when no memory driver is configured', function (): void {
    config(['ai.cognition.memory.driver' => AiMemoryDriver::Native->value]);

    expect(app(LongTermMemorySelector::class)->resolve())->toBeInstanceOf(NativeLongTermMemory::class);
});

test('an unrecognised memory driver is reported and still falls back to native recall', function (): void {
    Log::spy();
    config(['ai.cognition.memory.driver' => 'redis-stack']);

    expect(app(LongTermMemorySelector::class)->resolve())->toBeInstanceOf(NativeLongTermMemory::class);

    Log::shouldHaveReceived('warning')->once();
});

test('every declared memory driver resolves to a recall implementation', function (): void {
    foreach (AiMemoryDriver::cases() as $driver) {
        config(['ai.cognition.memory.driver' => $driver->value]);

        expect(app(LongTermMemorySelector::class)->resolve())->toBeInstanceOf(LongTermMemory::class);
    }
});
