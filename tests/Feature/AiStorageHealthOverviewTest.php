<?php

use Illuminate\Foundation\Application;
use Modules\AI\Actions\SummarizeAiStorageHealthAction;
use Modules\AI\Domain\Operability\AiStorageHealthOverview;
use OGame\Services\ModuleSlotService;
use Tests\IsolatedAccountTestCase;

class AiStorageHealthModuleTestCase extends IsolatedAccountTestCase
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

    /**
     * A storage snapshot as the observer hands it over: nothing being produced, half-full stores,
     * and whatever resource deltas just happened.
     *
     * @param list<array{kind:string, metal?:int, crystal?:int, deuterium?:int}> $deltas
     * @return array{production: array<string, int>, storage: array<string, array{amount:int, capacity:int}>, deltas: list<array<string, mixed>>}
     */
    public function snapshot(array $deltas = []): array
    {
        return [
            'production' => ['metal' => 0, 'crystal' => 0, 'deuterium' => 0],
            'storage' => [
                'metal' => ['amount' => 50_000, 'capacity' => 100_000],
                'crystal' => ['amount' => 50_000, 'capacity' => 100_000],
                'deuterium' => ['amount' => 50_000, 'capacity' => 100_000],
            ],
            'deltas' => $deltas,
        ];
    }
}

uses(AiStorageHealthModuleTestCase::class);

it('carries a trader exchange as an external, unpriced delta', function () {
    $overview = app(SummarizeAiStorageHealthAction::class)->handle($this->snapshot([
        ['kind' => 'trader_exchange', 'metal' => 40_000, 'crystal' => -20_000, 'deuterium' => -10_000],
    ]));

    expect($overview->deltas)->toHaveCount(1);

    $delta = $overview->deltas[0];

    expect($delta['kind'])->toBe('trader_exchange')
        ->and($delta['external'])->toBeTrue()
        ->and($delta['priced'])->toBeFalse()
        ->and($delta['ratio'])->toBeNull()
        ->and($delta['fee'])->toBeNull()
        ->and($delta['metal'])->toBe(40_000)
        ->and($delta['crystal'])->toBe(-20_000)
        ->and($delta['deuterium'])->toBe(-10_000);
});

it('leaves the production and overflow verdicts unchanged by a trader exchange', function () {
    $summarizer = app(SummarizeAiStorageHealthAction::class);

    $withoutDelta = $summarizer->handle($this->snapshot());

    // Big enough that booking the trade as production, or as stored metal, would flip both.
    $withDelta = $summarizer->handle($this->snapshot([
        ['kind' => 'trader_exchange', 'metal' => 5_000_000, 'crystal' => -2_000_000, 'deuterium' => -1_000_000],
    ]));

    expect($withoutDelta->productionVerdict)->toBe(AiStorageHealthOverview::PRODUCTION_IDLE)
        ->and($withoutDelta->overflowVerdict)->toBe(AiStorageHealthOverview::OVERFLOW_ROOM)
        ->and($withDelta->productionVerdict)->toBe($withoutDelta->productionVerdict)
        ->and($withDelta->overflowVerdict)->toBe($withoutDelta->overflowVerdict);
});

it('keeps a full, producing store reported as full when a trader exchange drains it', function () {
    $snapshot = $this->snapshot([
        ['kind' => 'trader_exchange', 'metal' => 10_000, 'crystal' => -5_000, 'deuterium' => -5_000],
    ]);
    $snapshot['production'] = ['metal' => 3_000, 'crystal' => 1_500, 'deuterium' => 0];
    $snapshot['storage']['metal']['amount'] = 100_000;

    $overview = app(SummarizeAiStorageHealthAction::class)->handle($snapshot);

    expect($overview->productionVerdict)->toBe(AiStorageHealthOverview::PRODUCTION_SURPLUS)
        ->and($overview->overflowVerdict)->toBe(AiStorageHealthOverview::OVERFLOW_FULL);
});

it('reads the overflow verdict at, past and below capacity', function (int $storedMetal, string $expected) {
    $snapshot = $this->snapshot();
    $snapshot['storage']['metal']['amount'] = $storedMetal;

    expect(app(SummarizeAiStorageHealthAction::class)->handle($snapshot)->overflowVerdict)->toBe($expected);
})->with([
    'nothing stored' => [0, AiStorageHealthOverview::OVERFLOW_ROOM],
    'one below capacity' => [99_999, AiStorageHealthOverview::OVERFLOW_ROOM],
    'exactly at capacity' => [100_000, AiStorageHealthOverview::OVERFLOW_FULL],
    'past capacity' => [150_000, AiStorageHealthOverview::OVERFLOW_FULL],
]);

it('does not mark a plain resource movement as an external exchange', function () {
    $overview = app(SummarizeAiStorageHealthAction::class)->handle($this->snapshot([
        ['kind' => 'expedition_return', 'metal' => 1_200, 'crystal' => 0, 'deuterium' => 0],
    ]));

    expect($overview->deltas)->toHaveCount(1)
        ->and($overview->deltas[0]['external'])->toBeFalse();
});
