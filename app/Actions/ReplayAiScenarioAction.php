<?php

namespace Modules\AI\Actions;

use Carbon\CarbonImmutable;
use Modules\AI\Domain\Decision\DecisionEngine;
use Modules\AI\Domain\Decision\ScoredCandidate;
use Modules\AI\Domain\Operability\AiScenarioReplay;
use Modules\AI\Domain\Perception\PerceptionSnapshot;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiProfile;
use RuntimeException;

/**
 * Replays a saved scenario through the real decision engine without writing anything.
 *
 * A replay is how one decision is questioned without touching an account: the scenario names the
 * persona, the legal observation and the frozen time, and the engine then has to produce the same
 * answer on every run. That is the property worth having — a decision nobody can reproduce is a
 * decision nobody can argue with.
 *
 * The persona is an unsaved profile, so replaying a scenario cannot create, change or enable an
 * account, and every field is read through an explicit accessor: a malformed scenario reports
 * which field is wrong instead of deciding from a half-read file.
 */
class ReplayAiScenarioAction
{
    /**
     * A reserved id that no real account can hold, so the synthetic persona is never read from
     * the profiles table even on a universe that has profiles.
     */
    private const SYNTHETIC_PLAYER_ID = 0;

    private const SCENARIO_DIRECTORY = 'resources/scenarios';

    /**
     * The scenarios that ship with the module. The admin page can only replay one of these, so
     * a page request cannot be turned into a read of an arbitrary file on the host.
     *
     * @return list<string>
     */
    public function names(): array
    {
        $names = [];

        foreach (glob(module_path('AI', self::SCENARIO_DIRECTORY . '/*.json')) ?: [] as $path) {
            $names[] = basename($path, '.json');
        }

        sort($names);

        return $names;
    }

    public function pathFor(string $name): string
    {
        if (!in_array($name, $this->names(), true)) {
            throw new RuntimeException('Unknown scenario: ' . $name . '. Known scenarios: ' . implode(', ', $this->names()) . '.');
        }

        return module_path('AI', self::SCENARIO_DIRECTORY . '/' . $name . '.json');
    }

    public function handle(string $path): AiScenarioReplay
    {
        $scenario = $this->load($path);
        $perception = $this->perception($scenario);
        $persona = $this->persona($scenario);
        $decisionKey = (string) $this->scalar($scenario, 'decision_key');
        $trace = app(DecisionEngine::class)->decide($persona, $perception, $decisionKey);
        $selected = $trace->selected;
        return app()->makeWith(AiScenarioReplay::class, [
            'name' => (string) $this->scalar($scenario, 'name'),
            'persona' => $persona->archetype->name . '/' . $persona->skill_band->name . ' seed ' . $persona->random_seed,
            'observedAt' => $perception->observedAt,
            'decisionKey' => $decisionKey,
            'selectedAction' => $selected->candidate->type->name,
            'selectedReason' => $selected->candidate->reason,
            'selectedScore' => $selected->score,
            'components' => $selected->components,
            'refusals' => $trace->rejections,
            'alternatives' => $this->alternatives($trace->candidates),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function load(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException('Scenario file not found: ' . $path);
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (!is_array($decoded)) {
            throw new RuntimeException('Scenario is not valid JSON: ' . $path);
        }

        // Presence is checked before anything is read, so a scenario that is missing a whole
        // section is reported as missing rather than as whatever field is read first.
        foreach (['name', 'persona', 'input', 'decision_key'] as $key) {
            if (!array_key_exists($key, $decoded)) {
                throw new RuntimeException('Scenario is missing "' . $key . '": ' . $path);
            }
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $scenario
     */
    private function perception(array $scenario): PerceptionSnapshot
    {
        $input = $this->section($scenario, 'input');

        return app()->makeWith(PerceptionSnapshot::class, [
            'playerId' => (int) $this->scalar($input, 'player_id'),
            'observedAt' => CarbonImmutable::parse((string) $this->scalar($input, 'observed_at')),
            'planets' => $this->planets($input),
            'targetReports' => $this->rows($input, 'target_reports'),
            'availableActions' => $this->flags($input, 'available_actions'),
            'fleetsaveEligible' => (bool) $this->scalar($input, 'fleetsave_eligible'),
            'recoveryFactor' => (float) $this->scalar($input, 'recovery_factor'),
            'sourceTimestamps' => $this->labels($input, 'source_timestamps'),
            'fleetSlotsFree' => (int) $this->scalar($input, 'fleet_slots_free'),
            'colonizeEligible' => (bool) $this->scalar($input, 'colonize_eligible'),
        ]);
    }

    /**
     * The persona is named by case name rather than by stored value, because a scenario file is
     * written by a person and "Miner" says more than the number it happens to be stored as.
     *
     * @param array<string, mixed> $scenario
     */
    private function persona(array $scenario): AiProfile
    {
        $persona = $this->section($scenario, 'persona');

        return AiProfile::query()->firstOrNew(['player_id' => self::SYNTHETIC_PLAYER_ID], [
            'archetype' => $this->archetype($persona),
            'skill_band' => $this->skillBand($persona),
            'random_seed' => (int) $this->scalar($persona, 'random_seed'),
            'enabled' => false,
        ]);
    }

    /**
     * @param array<string, mixed> $persona
     */
    private function archetype(array $persona): AiArchetype
    {
        $name = (string) $this->scalar($persona, 'archetype');

        foreach (AiArchetype::cases() as $case) {
            if (strcasecmp($case->name, $name) === 0) {
                return $case;
            }
        }

        throw new RuntimeException('Scenario names an unknown archetype: ' . $name . '.');
    }

    /**
     * @param array<string, mixed> $persona
     */
    private function skillBand(array $persona): AiSkillBand
    {
        $name = (string) $this->scalar($persona, 'skill_band');

        foreach (AiSkillBand::cases() as $case) {
            if (strcasecmp($case->name, $name) === 0) {
                return $case;
            }
        }

        throw new RuntimeException('Scenario names an unknown skill band: ' . $name . '.');
    }

    /**
     * @param array<int, ScoredCandidate> $scored
     * @return array<int, array{action: string, score: float}>
     */
    private function alternatives(array $scored): array
    {
        return collect($scored)
            ->map(static fn ($candidate): array => [
                'action' => $candidate->candidate->type->name,
                'score' => $candidate->score,
            ])
            ->sortByDesc('score')
            ->values()
            ->all();
    }

    /**
     * @param array<string, mixed> $source
     * @return array<int, array{id: int, resources: array<string, float|int>}>
     */
    private function planets(array $source): array
    {
        $planets = [];

        foreach ($this->rows($source, 'planets') as $planet) {
            $resources = [];

            foreach ($this->section($planet, 'resources') as $name => $amount) {
                if (!is_int($amount) && !is_float($amount)) {
                    throw new RuntimeException('Scenario planet resource "' . $name . '" must be a number.');
                }

                $resources[(string) $name] = $amount;
            }

            $planets[] = ['id' => (int) $this->scalar($planet, 'id'), 'resources' => $resources];
        }

        return $planets;
    }

    /**
     * @param array<string, mixed> $source
     * @return array<int, array<string, mixed>>
     */
    private function rows(array $source, string $key): array
    {
        $rows = [];

        foreach ($this->section($source, $key) as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('Scenario field "' . $key . '" must be a list of objects.');
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, bool>
     */
    private function flags(array $source, string $key): array
    {
        $flags = [];

        foreach ($this->section($source, $key) as $name => $value) {
            if (!is_bool($value)) {
                throw new RuntimeException('Scenario flag "' . $name . '" must be true or false.');
            }

            $flags[(string) $name] = $value;
        }

        return $flags;
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, string>
     */
    private function labels(array $source, string $key): array
    {
        $labels = [];

        foreach ($this->section($source, $key) as $name => $value) {
            if (!is_string($value)) {
                throw new RuntimeException('Scenario label "' . $name . '" must be a string.');
            }

            $labels[(string) $name] = $value;
        }

        return $labels;
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function section(array $source, string $key): array
    {
        $value = $source[$key] ?? null;

        if (!is_array($value)) {
            throw new RuntimeException('Scenario field "' . $key . '" must be an object.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $source
     */
    private function scalar(array $source, string $key): string|int|float|bool
    {
        $value = $source[$key] ?? null;

        if (!is_scalar($value)) {
            throw new RuntimeException('Scenario field "' . $key . '" must be a scalar value.');
        }

        return $value;
    }
}
