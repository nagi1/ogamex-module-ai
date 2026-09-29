<?php

use Illuminate\Foundation\Application;
use Modules\AI\Domain\Decision\CandidateActionFactory;
use Modules\AI\Domain\Perception\PerceptionSnapshot;
use Modules\AI\Enums\AiCapability;
use OGame\Services\ModuleSlotService;
use Tests\IsolatedAccountTestCase;

class EnergyUnconstrainedModuleTestCase extends IsolatedAccountTestCase
{
    private const PLAYER_ID = 1;

    private const FLEET_SLOTS_FREE = 4;

    private const SURPLUS_PRODUCTION = 900;

    private const SURPLUS_CONSUMPTION = 100;

    private const DEFICIT_PRODUCTION = 100;

    private const DEFICIT_CONSUMPTION = 900;

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

    public function surplusSnapshot(): PerceptionSnapshot
    {
        return $this->snapshotAtEnergy(self::SURPLUS_PRODUCTION, self::SURPLUS_CONSUMPTION);
    }

    public function deficitSnapshot(): PerceptionSnapshot
    {
        return $this->snapshotAtEnergy(self::DEFICIT_PRODUCTION, self::DEFICIT_CONSUMPTION);
    }

    /**
     * The account is identical in both calls apart from the energy it produces and burns, so any
     * difference in the offer would have to come from energy and nothing else.
     */
    public function snapshotAtEnergy(int $production, int $consumption): PerceptionSnapshot
    {
        $overrides = [
            'energyProduction' => $production,
            'energyConsumption' => $consumption,
        ];

        if ($this->planetsArePlainArrays()) {
            $overrides['planets'] = [$this->planetAtEnergy($production, $consumption)];
        }

        return $this->snapshot($overrides);
    }

    /** @param array<string, mixed> $overrides */
    public function snapshot(array $overrides): PerceptionSnapshot
    {
        return app()->makeWith(PerceptionSnapshot::class, $this->snapshotArguments($overrides));
    }

    /**
     * The factory reads this snapshot and nothing outside it, so every field it can read is pinned
     * to one value and only the energy figures are allowed to move between the two calls.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function snapshotArguments(array $overrides): array
    {
        $arguments = $overrides + [
            'playerId' => self::PLAYER_ID,
            'availableActions' => $this->publishedCapabilities(),
            'sourceTimestamps' => [],
            'fleetSlotsFree' => self::FLEET_SLOTS_FREE,
            'fleetsaveEligible' => false,
            'colonizeEligible' => true,
            'recoveryFactor' => 1.0,
        ];

        foreach ($this->snapshotConstructor()->getParameters() as $parameter) {
            $name = $parameter->getName();

            if (array_key_exists($name, $arguments) || ! $this->isBuiltin($parameter)) {
                continue;
            }

            $arguments[$name] = $parameter->isDefaultValueAvailable()
                ? $parameter->getDefaultValue()
                : $this->zeroOf($parameter);
        }

        return $arguments;
    }

    private function snapshotConstructor(): \ReflectionMethod
    {
        return (new \ReflectionClass(PerceptionSnapshot::class))->getConstructor();
    }

    private function isBuiltin(\ReflectionParameter $parameter): bool
    {
        $type = $parameter->getType();

        return $type instanceof \ReflectionNamedType && $type->isBuiltin();
    }

    private function zeroOf(\ReflectionParameter $parameter): int|float|string|bool|array|null
    {
        /** @var \ReflectionNamedType $type */
        $type = $parameter->getType();

        return match ($type->getName()) {
            'int' => 0,
            'float' => 0.0,
            'string' => '',
            'bool' => false,
            'array' => [],
            default => null,
        };
    }

    /**
     * The factory walks planets as plain arrays, so the energy figures ride along in that same
     * shape; a snapshot that models its planets some other way still gets the energy on its own
     * fields rather than being forced into an array it would reject.
     */
    private function planetsArePlainArrays(): bool
    {
        foreach ($this->snapshotConstructor()->getParameters() as $parameter) {
            if ($parameter->getName() !== 'planets') {
                continue;
            }

            $type = $parameter->getType();

            return $type instanceof \ReflectionNamedType && $type->getName() === 'array';
        }

        return false;
    }

    /**
     * Metal, crystal and deuterium stay at the same figures in both variants, so whatever the
     * snapshot sums out of a planet is unchanged and only energy moves.
     *
     * @return array<string, mixed>
     */
    private function planetAtEnergy(int $production, int $consumption): array
    {
        return [
            'resources' => [
                'metal' => 0.0,
                'crystal' => 0.0,
                'deuterium' => 0.0,
            ],
            'energyProduction' => $production,
            'energyConsumption' => $consumption,
        ];
    }

    /** @return array<string, bool> */
    private function publishedCapabilities(): array
    {
        $actions = [];

        foreach (AiCapability::cases() as $capability) {
            $actions[$capability->value] = true;
        }

        return $actions;
    }
}

uses(EnergyUnconstrainedModuleTestCase::class);

it('offers the same candidate actions when the planet is short of energy as when it has a surplus', function () {
    $factory = app(CandidateActionFactory::class);

    $surplus = $factory->create($this->surplusSnapshot());
    $deficit = $factory->create($this->deficitSnapshot());

    expect($surplus->candidates)->not->toBeEmpty()
        ->and($deficit->candidates)->toEqual($surplus->candidates);
});

it('rejects the same candidates when the planet is short of energy as when it has a surplus', function () {
    $factory = app(CandidateActionFactory::class);

    $surplus = $factory->create($this->surplusSnapshot());
    $deficit = $factory->create($this->deficitSnapshot());

    expect($deficit->rejections)->toEqual($surplus->rejections);
});

it('offers the same actions at zero energy production as at zero energy consumption', function () {
    $factory = app(CandidateActionFactory::class);

    $nothingProduced = $factory->create($this->snapshotAtEnergy(0, 1_000_000));
    $nothingConsumed = $factory->create($this->snapshotAtEnergy(1_000_000, 0));

    expect($nothingProduced->candidates)->toEqual($nothingConsumed->candidates);
});
