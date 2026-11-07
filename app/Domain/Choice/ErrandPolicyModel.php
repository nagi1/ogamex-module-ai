<?php

namespace Modules\AI\Domain\Choice;

use JsonException;

/**
 * The errand model the simulator trains (plan/rl, `ogsim::season::Policy`), run in PHP: the same network on the same
 * columns, so the deployed game asks it what the simulator's accounts ask it. Only a policy whose header carries the
 * wide feature version and the exact column names of `WideErrandEncoder` is loaded; anything else is null and the
 * errand path keeps today's behaviour (a stale or narrow model must never read columns it was not trained on).
 */
class ErrandPolicyModel
{
    /** @var array<string, self|null> one parse per file and modification time */
    private static array $loaded = [];

    /**
     * @param array{0: list<float>, 1: list<float>, 2: list<float>, 3: list<float>} $norm state mean, state std, candidate mean, candidate std
     * @param array<string, array{weight: list<list<float>>, bias: list<float>}> $layers
     */
    public function __construct(private array $norm, private array $layers)
    {
    }

    /** The configured errand model (`ai.rl.errand_model`), or null when none is set or it is not a wide one. */
    public static function configured(): self|null
    {
        $path = config('ai.rl.errand_model');
        if (!is_string($path) || $path === '' || !is_file($path)) {
            return null;
        }

        $key = $path . ':' . filemtime($path);

        return array_key_exists($key, self::$loaded) ? self::$loaded[$key] : self::$loaded[$key] = self::parse((string) file_get_contents($path));
    }

    public static function parse(string $json): self|null
    {
        try {
            $raw = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        $names = is_array($raw) ? ($raw['_names'] ?? null) : null;
        if (($raw['_errand_feature_version'] ?? null) !== WideErrandEncoder::VERSION || !is_array($names)
            || ($names['state'] ?? null) !== WideErrandEncoder::STATE || ($names['candidate'] ?? null) !== WideErrandEncoder::candidateNames()) {
            return null;
        }

        $layers = [];
        foreach (['state_net.0', 'state_net.2', 'cand_net.0', 'joint.0', 'joint.2', 'joint.4'] as $layer) {
            $layers[$layer] = ['weight' => $raw[$layer . '.weight'], 'bias' => $raw[$layer . '.bias']];
        }

        return app()->makeWith(self::class, ['norm' => [$raw['state_mean'], $raw['state_std'], $raw['cand_mean'], $raw['cand_std']], 'layers' => $layers]);
    }

    /** The legal row with the highest score; the last of equal ones, as Rust's `max_by` takes it. */
    public function best(ChoicePoint $point): int
    {
        $best = 0;
        $top = -INF;
        foreach ($this->scores($point) as $row => $score) {
            if ($score >= $top) {
                [$best, $top] = [$row, $score];
            }
        }

        return $best;
    }

    /**
     * Every legal row's score (`Policy::scores`): the state through its two layers once, each candidate through its
     * own, then the joint layers on both.
     *
     * @return array<int, float>
     */
    public function scores(ChoicePoint $point): array
    {
        $state = $this->apply('state_net.2', $this->apply('state_net.0', $this->scaled($point->state, 0)));
        $scores = [];
        foreach ($point->candidates as $row => $candidate) {
            if (!$candidate->legal) {
                continue;
            }
            $joint = [...$state, ...$this->apply('cand_net.0', $this->scaled($candidate->features, 2))];
            $scores[$row] = $this->apply('joint.4', $this->apply('joint.2', $this->apply('joint.0', $joint)), false)[0];
        }

        return $scores;
    }

    /**
     * @param list<float> $values
     * @return list<float>
     */
    private function scaled(array $values, int $at): array
    {
        return array_map(fn (float $value, int $i): float => ($value - $this->norm[$at][$i]) / $this->norm[$at + 1][$i], $values, array_keys($values));
    }

    /**
     * @param list<float> $input
     * @return list<float>
     */
    private function apply(string $layer, array $input, bool $relu = true): array
    {
        $out = [];
        foreach ($this->layers[$layer]['weight'] as $o => $row) {
            $sum = $this->layers[$layer]['bias'][$o];
            foreach ($row as $i => $weight) {
                $sum += $weight * $input[$i];
            }
            $out[] = $relu ? max(0.0, $sum) : $sum;
        }

        return $out;
    }
}
