<?php

use Modules\AI\Actions\QueueAiOfficerAction;
use Modules\AI\Domain\Decision\QueueableOfficerPlanner;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use OGame\Models\Officer;
use OGame\Models\User;
use OGame\Services\OfficerService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

beforeEach(function (): void {
    AiProfile::create(['player_id' => $this->currentUserId, 'archetype' => AiArchetype::Miner, 'skill_band' => AiSkillBand::Standard, 'random_seed' => 5, 'enabled' => true]);
});

function officerBalance(int $playerId, int $darkMatter): void
{
    User::query()->whereKey($playerId)->update(['dark_matter' => $darkMatter]);
    app(OfficerService::class)->clearCache(User::query()->findOrFail($playerId));
}

// A veteran buys the officer whose benefit the account already uses, and never spends the last of the dark matter.
test('the account buys the officer whose benefit it already uses, above the reserve', function (int $mineLevel, int $darkMatter, string|null $officer, int|null $days): void {
    $this->planetSetObjectLevel('metal_mine', $mineLevel);
    officerBalance($this->currentUserId, $darkMatter);

    $plan = app(QueueableOfficerPlanner::class)->plan($this->currentUserId);

    expect($plan?->officerKey)->toBe($officer)
        ->and($plan?->days)->toBe($days);
})->with([
    'mines running and the dark matter to spare' => [8, 20_000, 'geologist', 7],
    'a long term when it still leaves the reserve' => [8, 200_000, 'geologist', 90],
    'only the last of it: nothing' => [8, 12_000, null, null],
    'no mines worth multiplying: nothing' => [3, 20_000, null, null],
]);

test('a held officer is renewed only when it is about to lapse', function (int $daysLeft, string|null $officer): void {
    $this->planetSetObjectLevel('metal_mine', 8);
    $held = Officer::query()->firstOrNew(['user_id' => $this->currentUserId]);
    $held->geologist_until = now()->addDays($daysLeft);
    $held->save();
    officerBalance($this->currentUserId, 20_000);

    expect(app(QueueableOfficerPlanner::class)->plan($this->currentUserId)?->officerKey)->toBe($officer);
})->with(['a month left' => [30, null], 'a day left' => [1, 'geologist']]);

test('the purchase goes through the host and is debited once', function (): void {
    officerBalance($this->currentUserId, 20_000);

    $result = app(QueueAiOfficerAction::class)->handle($this->currentUserId, 'geologist', 7);
    app(OfficerService::class)->clearCache(User::query()->findOrFail($this->currentUserId));

    expect($result->successful)->toBeTrue()
        ->and(User::query()->findOrFail($this->currentUserId)->dark_matter)->toBe(7_500)
        ->and(app(OfficerService::class)->isActive(User::query()->findOrFail($this->currentUserId), 'geologist'))->toBeTrue()
        ->and(app(QueueAiOfficerAction::class)->handle($this->currentUserId, 'geologist', 90)->successful)->toBeFalse();
});
