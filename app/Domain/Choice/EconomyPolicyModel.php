<?php

namespace Modules\AI\Domain\Choice;

use JsonException;
use OGame\Models\Enums\PlanetType;
use OGame\Models\Highscore;
use OGame\Models\Planet;
use OGame\Services\SettingsService;

/**
 * The economy model the simulator trains (`ogsim::season`, the learner's build choice), run in PHP on the game's own
 * economy choice points.
 *
 * The simulator computes its columns with EconomyChoiceEncoder's formulas and names (`economy.rs` STATE_NAMES and
 * CAND_NAMES), so the planet and candidate columns are a pick by name from the point the game already builds. Beside
 * them the learner reads eight account columns (`season.rs` ACCOUNT_NAMES), read here from the host's highscore, and
 * one advice column: 1 on the row the persona would take, which on the host is the planner's own pick.
 *
 * The model learned in one universe speed (its speed column barely varies). OGame's economy is the same game at any
 * speed with the clock scaled: production per hour grows with the speed, build times shrink with it, and prices,
 * stores, energy and levels do not move. So the time columns are read in the training universe's hours: rates divided
 * and durations multiplied by speed / training speed, and the speed column set to the training speed. Without this a
 * 1000x universe would sit hundreds of standard deviations outside everything the model saw.
 */
class EconomyPolicyModel
{
    /** The planet columns the model reads, in its order (`economy.rs` STATE_NAMES). */
    public const STATE = [
        'speed_economy', 'pl_metal', 'pl_crystal', 'pl_deuterium', 'pl_metal_fill', 'pl_crystal_fill', 'pl_deuterium_fill',
        'pl_metal_ph', 'pl_crystal_ph', 'pl_deuterium_ph', 'pl_energy_max', 'pl_energy_used', 'pl_factor', 'pl_temperature',
        'pl_fields_used', 'pl_fields_max', 'pl_position',
    ];

    /** The account columns after them (`season.rs` ACCOUNT_NAMES). */
    public const ACCOUNT = ['age_years', 'is_research', 'eco_share', 'res_share', 'mil_share', 'ln_score', 'rank', 'planets'];

    /** The candidate columns (`economy.rs` CAND_NAMES), then the advice column. */
    public const CANDIDATE = [
        'cost_metal', 'cost_crystal', 'cost_deuterium', 'cost_hours', 'cost_vs_stock', 'build_hours', 'level', 'gain_ph',
        'payback_hours', 'energy_delta', 'storage_gain', 'unlocks', 'eta_hours',
    ];

    public const ADVICE = 'advice';

    /** Rates: per hour, so they shrink into the training universe's hours. */
    private const RATES = ['pl_metal_ph', 'pl_crystal_ph', 'pl_deuterium_ph', 'gain_ph'];

    /** Durations: hours, so they grow into the training universe's hours (the encoder caps them at FAR_HOURS). */
    private const DURATIONS = ['cost_hours', 'build_hours', 'payback_hours', 'eta_hours'];

    private const FAR_HOURS = 10_000.0;

    private const YEAR_SECONDS = 365 * 86_400;

    /** @var array<string, self|null> one parse per file and modification time */
    private static array $loaded = [];

    /**
     * @param array{0: list<float>, 1: list<float>, 2: list<float>, 3: list<float>} $norm state mean, state std, candidate mean, candidate std
     * @param array<string, array{weight: list<list<float>>, bias: list<float>}> $layers
     */
    public function __construct(private array $norm, private array $layers)
    {
    }

    /** The configured economy model (`ai.rl.economy_model`), or null when none is set or the file is not one. */
    public static function configured(): self|null
    {
        $path = config('ai.rl.economy_model');
        if (!is_string($path) || $path === '' || !is_file($path)) {
            return null;
        }
        $key = $path . ':' . filemtime($path);

        return array_key_exists($key, self::$loaded) ? self::$loaded[$key] : self::$loaded[$key] = self::parse((string) file_get_contents($path));
    }

    /** A policy whose normalisation has exactly this model's column counts; anything else is null. */
    public static function parse(string $json): self|null
    {
        try {
            $raw = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        $state = count(self::STATE) + count(self::ACCOUNT);
        $candidate = count(self::CANDIDATE) + 1;
        if (!is_array($raw) || count((array) ($raw['state_mean'] ?? [])) !== $state || count((array) ($raw['cand_mean'] ?? [])) !== $candidate
            || count((array) ($raw['state_net.0.weight'][0] ?? [])) !== $state || count((array) ($raw['cand_net.0.weight'][0] ?? [])) !== $candidate) {
            return null;
        }

        $layers = [];
        foreach (['state_net.0', 'state_net.2', 'cand_net.0', 'joint.0', 'joint.2', 'joint.4'] as $layer) {
            $layers[$layer] = ['weight' => $raw[$layer . '.weight'], 'bias' => $raw[$layer . '.bias']];
        }

        return app()->makeWith(self::class, ['norm' => [$raw['state_mean'], $raw['state_std'], $raw['cand_mean'], $raw['cand_std']], 'layers' => $layers]);
    }

    /** The universe speed the model learned in: its speed column's mean, which the trainer saw as ln(speed). */
    public function trainingSpeed(): float
    {
        return exp((float) $this->norm[0][0]);
    }

    /**
     * The row the model takes: the best-scored row it may take (payable now, or worth saving for, as the simulator's
     * legal rows are), and waiting (row 0) when that row cannot be paid yet, as the simulator's account logs off.
     * Without `advice` the planner's pick is hidden from it: what the model would do on its own (the lab shows both).
     */
    public function choose(ChoicePoint $point, bool $advice = true): int
    {
        $scores = $this->scores($point, $advice);
        $best = 0;
        $top = -INF;
        foreach ($scores as $row => $score) {
            if ($score > $top) {
                [$best, $top] = [$row, $score];
            }
        }

        return $point->candidates[$best]->legal ? $best : 0;
    }

    /**
     * Every row's score, for the rows the model may take.
     *
     * @return array<int, float>
     */
    public function scores(ChoicePoint $point, bool $advice = true): array
    {
        $columns = $this->columns($point, $advice);
        $state = $this->apply('state_net.2', $this->apply('state_net.0', $this->scaled($columns['state'], 0)));
        $scores = [];
        foreach ($columns['candidates'] as $row => $features) {
            $joint = [...$state, ...$this->apply('cand_net.0', $this->scaled($features, 2))];
            $scores[$row] = $this->apply('joint.4', $this->apply('joint.2', $this->apply('joint.0', $joint)), false)[0];
        }

        return $scores;
    }

    /**
     * What the model reads at this point, in its own units: the state, and one row per row it may take.
     *
     * @return array{state: list<float>, candidates: array<int, list<float>>}
     */
    public function columns(ChoicePoint $point, bool $advice = true): array
    {
        $scale = max(1.0, (float) app(SettingsService::class)->economySpeed()) / $this->trainingSpeed();
        $stateIndex = array_flip(EconomyChoiceEncoder::stateNames());
        $candidateIndex = array_flip(EconomyChoiceEncoder::candidateNames());

        $state = [];
        foreach (self::STATE as $name) {
            $state[] = $name === 'speed_economy' ? (float) $this->norm[0][0] : $this->inTrainingHours($name, $point->state[$stateIndex[$name]] ?? 0.0, $scale);
        }
        $state = [...$state, ...$this->account($point, $scale)];

        $candidates = [];
        foreach ($point->candidates as $row => $candidate) {
            if ($row !== 0 && !$candidate->buildable()) {
                continue;
            }
            $features = [];
            foreach (self::CANDIDATE as $name) {
                // The simulator's waiting row is all zeros but its advice.
                $features[] = $row === 0 ? 0.0 : $this->inTrainingHours($name, $candidate->features[$candidateIndex[$name]] ?? 0.0, $scale);
            }
            $features[] = $advice && $row === $point->teacherIndex ? 1.0 : 0.0;
            $candidates[$row] = $features;
        }

        return ['state' => $state, 'candidates' => $candidates];
    }

    /**
     * The account's eight columns, as `Season::observe` writes them: its age in years, whether the lab decides, its
     * score shares, ln of its score, its rank as a share of the table (best is near 0) and its planets over 16.
     *
     * @return list<float>
     */
    private function account(ChoicePoint $point, float $scale): array
    {
        $highscore = Highscore::query()->where('player_id', $point->playerId)->first();
        $total = max(1.0, (float) ($highscore->general ?? 0));
        $ranked = max(1, Highscore::query()->count());
        // The simulator's clock starts with the season, when the account founds its first planet; a host account row can be older.
        $created = Planet::query()->where('user_id', $point->playerId)->min('created_at');
        $age = $created === null ? 0.0 : max(0.0, (float) now()->getTimestamp() - (float) strtotime((string) $created));
        $planets = max(1, Planet::query()->where('user_id', $point->playerId)->where('planet_type', PlanetType::Planet->value)->count());

        return [
            $age * $scale / self::YEAR_SECONDS,
            $point->isResearch() ? 1.0 : 0.0,
            (float) ($highscore->economy ?? 0) / $total,
            (float) ($highscore->research ?? 0) / $total,
            // The host's table keeps the military built; it stands in for the simulator's military held.
            (float) ($highscore->military_built ?? 0) / $total,
            log($total),
            $highscore?->general_rank !== null ? min(1.0, $highscore->general_rank / $ranked) : 1.0,
            $planets / 16,
        ];
    }

    /** A column the encoder wrote in this universe's hours, in the training universe's (rates shrink, durations grow). */
    private function inTrainingHours(string $name, float $value, float $scale): float
    {
        if (in_array($name, self::RATES, true)) {
            $raw = $value < 0 ? -expm1(-$value) : expm1($value);
            $raw /= $scale;

            return $raw < 0 ? -log1p(-$raw) : log1p($raw);
        }
        if (in_array($name, self::DURATIONS, true)) {
            return log1p(min(self::FAR_HOURS, expm1($value) * $scale));
        }

        return $value;
    }

    /**
     * @param list<float> $values
     * @return list<float>
     */
    private function scaled(array $values, int $at): array
    {
        return array_map(fn (float $value, int $i): float => ($value - (float) $this->norm[$at][$i]) / max(1e-9, (float) $this->norm[$at + 1][$i]), $values, array_keys($values));
    }

    /**
     * @param list<float> $input
     * @return list<float>
     */
    private function apply(string $layer, array $input, bool $relu = true): array
    {
        $out = [];
        foreach ($this->layers[$layer]['weight'] as $o => $row) {
            $sum = (float) $this->layers[$layer]['bias'][$o];
            foreach ($row as $i => $weight) {
                $sum += $weight * $input[$i];
            }
            $out[] = $relu ? max(0.0, $sum) : $sum;
        }

        return $out;
    }
}
