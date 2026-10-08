<?php

use Modules\AI\Domain\Decision\QueueableTradePlanner;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use OGame\Models\Resources;
use OGame\Models\User;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// Late-game stores dwarf any stock, so the overflow rule never fired: accounts held millions of metal and deuterium beside
// forty thousand crystal, and every crystal-bound dome and missile waited (9419, 9421: no shield dome built).
test('a stock that dwarfs a thin one goes to the merchant however large the store', function (int $crystal, string|null $receive): void {
    AiProfile::create(['player_id' => $this->currentUserId, 'archetype' => AiArchetype::Miner, 'skill_band' => AiSkillBand::Standard, 'random_seed' => 5, 'enabled' => true]);
    User::query()->whereKey($this->currentUserId)->update(['dark_matter' => 10_000]);
    foreach (['metal_store', 'crystal_store', 'deuterium_store'] as $store) {
        $this->planetSetObjectLevel($store, 15);
    }
    $held = $this->planetService->getResources();
    $this->planetDeductResources($held);
    $this->planetAddResources(new Resources(10_000_000, $crystal, 10_000_000));

    expect(app(QueueableTradePlanner::class)->plan($this->currentUserId)?->receiveResource)->toBe($receive);
})->with([
    'no crystal beside millions: crystal is bought' => [1_000, 'crystal'],
    'a balanced pile keeps its dark matter' => [5_000_000, null],
]);
