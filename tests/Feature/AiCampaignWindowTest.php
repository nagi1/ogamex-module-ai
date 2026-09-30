<?php

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Application;
use InvalidArgumentException;
use Modules\AI\Actions\OpenAiCampaignAction;
use Modules\AI\Enums\AiCampaignState;
use Modules\AI\Models\AiCampaign;
use OGame\Services\ModuleSlotService;
use Tests\IsolatedAccountTestCase;

class AiCampaignWindowModuleTestCase extends IsolatedAccountTestCase
{
    private string $statusesFile;

    public function createApplication(): Application
    {
        $trackedFile = dirname(__DIR__, 4) . '/modules_statuses.json';
        $statuses = json_decode((string) file_get_contents($trackedFile), true);
        $statuses['AI'] = true;
        $this->statusesFile = sys_get_temp_dir() . '/modules_statuses_' . uniqid('', true) . '.json';
        file_put_contents($this->statusesFile, json_encode($statuses, JSON_PRETTY_PRINT));
        putenv('MODULES_STATUSES_FILE=' . $this->statusesFile);

        return parent::createApplication();
    }

    protected function tearDown(): void
    {
        if (is_file($this->statusesFile)) {
            unlink($this->statusesFile);
        }

        putenv('MODULES_STATUSES_FILE');

        // The slot registry is static, so a test that registered the module's nav link would
        // leak it into the next one.
        ModuleSlotService::resetSlots();

        parent::tearDown();
    }
}

uses(AiCampaignWindowModuleTestCase::class);

it('opens a campaign across the window it is given', function () {
    $startsAt = CarbonImmutable::parse('2026-01-01 00:00:00');
    $endsAt = CarbonImmutable::parse('2026-01-03 00:00:00');

    $campaign = app(OpenAiCampaignAction::class)->handle($startsAt, $endsAt);

    // Re-read from the database so the assertion covers what was persisted, not what the
    // caller happened to hold in memory.
    $persisted = AiCampaign::query()->findOrFail($campaign->id);

    expect($persisted->state)->toBe(AiCampaignState::Preparing)
        ->and($persisted->starts_at->format('Y-m-d H:i:s'))->toBe('2026-01-01 00:00:00')
        ->and($persisted->ends_at->format('Y-m-d H:i:s'))->toBe('2026-01-03 00:00:00');
});

it('accepts the tightest window that still ends after it starts', function () {
    $startsAt = CarbonImmutable::parse('2026-01-01 00:00:00');
    $endsAt = $startsAt->addSecond();

    $campaign = app(OpenAiCampaignAction::class)->handle($startsAt, $endsAt);

    $persisted = AiCampaign::query()->findOrFail($campaign->id);

    expect($persisted->starts_at->format('Y-m-d H:i:s'))->toBe('2026-01-01 00:00:00')
        ->and($persisted->ends_at->format('Y-m-d H:i:s'))->toBe('2026-01-01 00:00:01');
});

it('refuses a window that ends at the instant it starts', function () {
    $instant = CarbonImmutable::parse('2026-01-01 00:00:00');

    expect(fn () => app(OpenAiCampaignAction::class)->handle($instant, $instant))
        ->toThrow(InvalidArgumentException::class, 'A campaign must end after it starts.');

    expect(AiCampaign::query()->count())->toBe(0);
});

it('refuses a window that ends before it starts', function () {
    $startsAt = CarbonImmutable::parse('2026-01-03 00:00:00');
    $endsAt = CarbonImmutable::parse('2026-01-01 00:00:00');

    expect(fn () => app(OpenAiCampaignAction::class)->handle($startsAt, $endsAt))
        ->toThrow(InvalidArgumentException::class, 'A campaign must end after it starts.');

    expect(AiCampaign::query()->count())->toBe(0);
});
