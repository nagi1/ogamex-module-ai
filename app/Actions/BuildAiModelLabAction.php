<?php

namespace Modules\AI\Actions;

use Illuminate\Support\Facades\DB;
use JsonException;
use Modules\AI\Domain\Choice\ChoicePoint;
use Modules\AI\Domain\Choice\ErrandPolicyModel;
use Modules\AI\Domain\Choice\WideErrandEncoder;
use Modules\AI\Domain\Decision\DecisionEngine;
use Modules\AI\Domain\Login\LoginPlans;
use Modules\AI\Domain\Perception\PlayerPerceptionBuilder;
use Modules\AI\Models\AiProfile;
use OGame\Factories\PlayerServiceFactory;
use Throwable;

/**
 * The model lab (config `ai.model_lab`): the trained weight models run on the live accounts' real game state.
 *
 * Each scenario is the login an account would have now: the same perception, the same engine ranking and the same wide
 * errand encoder the session uses, scored by the same `ErrandPolicyModel` the game deploys. It is a dry run: everything
 * happens inside a transaction that is rolled back, and nothing is queued, so opening the page never moves the game.
 */
class BuildAiModelLabAction
{
    /** @var array<string, array<string, mixed>|null> one parse per file and modification time */
    private static array $raw = [];

    public function __construct(
        private PlayerPerceptionBuilder $perceptions,
        private DecisionEngine $engine,
        private PlayerServiceFactory $players,
        private WideErrandEncoder $encoder,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function handle(string|null $modelKey, int|null $playerId): array
    {
        $models = $this->models();
        $runnable = array_filter($models, static fn (array $model): bool => $model['runnable']);
        $selected = $modelKey !== null && isset($runnable[$modelKey]) ? $modelKey : (string) (array_key_first($runnable) ?? '');
        $profiles = AiProfile::query()->orderBy('player_id')->get();

        $overview = [];
        if ($selected !== '') {
            foreach ($profiles as $profile) {
                $overview[] = $this->summary($profile, $this->scenario($profile, $models[$selected]));
            }
        }

        $detail = null;
        $profile = $playerId === null ? null : $profiles->firstWhere('player_id', $playerId);
        if ($profile instanceof AiProfile && $selected !== '') {
            $detail = $this->scenario($profile, $models[$selected]);
            $detail['compare'] = [];
            foreach ($runnable as $key => $model) {
                $other = $key === $selected ? $detail : $this->scenario($profile, $model);
                $detail['compare'][] = ['key' => $key, 'label' => $model['label'], 'pick' => $other['model_pick_label'] ?? '-', 'share' => $other['model_pick_share'] ?? null, 'agrees' => $other['agrees'] ?? null, 'value' => $other['value'] ?? null];
            }
        }

        return [
            'models' => $models,
            'selected' => $selected,
            'overview' => $overview,
            'agreement' => $this->agreement($overview),
            'detail' => $detail,
            'player_id' => $profile instanceof AiProfile ? $profile->player_id : null,
            'sigma' => (float) config('ai.model_lab.outlier_sigma', 4.0),
        ];
    }

    /**
     * The configured models, each with what its file says about itself and whether it can run on the game's state.
     *
     * @return array<string, array<string, mixed>>
     */
    private function models(): array
    {
        $models = [];
        foreach (array_filter(array_map('trim', explode(',', (string) config('ai.model_lab.models', '')))) as $entry) {
            [$label, $path] = array_pad(array_map('trim', explode('=', $entry, 2)), 2, '');
            if ($path === '') {
                continue;
            }
            $path = str_starts_with($path, '/') ? $path : storage_path('rl/ogsim/runs/' . $path);
            $raw = $this->raw($path);
            $model = $raw === null ? null : ErrandPolicyModel::parse((string) file_get_contents($path));
            $models[md5($path)] = [
                'label' => $label,
                'path' => $path,
                'exists' => is_file($path),
                'trained_at' => is_file($path) ? date('Y-m-d H:i', (int) filemtime($path)) : null,
                'deployed' => $path === config('ai.rl.errand_model'),
                'runnable' => $model !== null,
                'model' => $model,
                'raw' => $raw,
                'shape' => $raw === null ? [] : $this->shape($raw),
                'why_not' => $model !== null ? null : $this->whyNot($path, $raw),
            ];
        }

        return $models;
    }

    /** @return array<string, mixed>|null */
    private function raw(string $path): array|null
    {
        if (!is_file($path)) {
            return null;
        }
        $key = $path . ':' . filemtime($path);
        if (!array_key_exists($key, self::$raw)) {
            try {
                $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
                self::$raw[$key] = is_array($decoded) ? $decoded : null;
            } catch (JsonException) {
                self::$raw[$key] = null;
            }
        }

        return self::$raw[$key];
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, string>
     */
    private function shape(array $raw): array
    {
        $shape = [];
        foreach ($raw as $name => $value) {
            if (str_ends_with((string) $name, '.weight') && is_array($value) && is_array($value[0] ?? null)) {
                $shape[substr((string) $name, 0, -7)] = count($value[0]) . ' → ' . count($value);
            }
        }

        return $shape;
    }

    /** @param array<string, mixed>|null $raw */
    private function whyNot(string $path, array|null $raw): string
    {
        if (!is_file($path)) {
            return __('t_ai.lab_why_missing');
        }
        if ($raw === null) {
            return __('t_ai.lab_why_unreadable');
        }
        if (($raw['_errand_feature_version'] ?? null) === null) {
            return __('t_ai.lab_why_layout', [
                'state' => count((array) ($raw['state_mean'] ?? [])),
                'candidate' => count((array) ($raw['cand_mean'] ?? [])),
            ]);
        }

        return __('t_ai.lab_why_version', ['version' => (string) $raw['_errand_feature_version'], 'expected' => WideErrandEncoder::VERSION]);
    }

    /**
     * One account's login now, scored by one model.
     *
     * @param array<string, mixed> $model
     * @return array<string, mixed>
     */
    private function scenario(AiProfile $profile, array $model): array
    {
        $started = microtime(true);
        $plans = app(LoginPlans::class);
        DB::beginTransaction();
        try {
            $plans->reset();
            $perception = $this->perceptions->build($profile->player_id, 60);
            $trace = $this->engine->decide($profile, $perception, 'model-lab:' . $profile->player_id);
            $player = $this->players->make($profile->player_id, true);
            $point = $player->planets->all() === [] ? null : $this->encoder->point($profile->player_id, $player, $trace, 'model-lab:' . $profile->player_id);
        } catch (Throwable $exception) {
            return ['profile' => $profile, 'error' => $exception->getMessage()];
        } finally {
            $plans->reset();
            DB::rollBack();
        }
        if ($point === null) {
            return ['profile' => $profile, 'error' => __('t_ai.lab_no_planets')];
        }

        /** @var ErrandPolicyModel $network */
        $network = $model['model'];
        $scores = $network->scores($point);
        $again = $network->scores($point);
        $pick = $network->best($point);
        $shares = $this->softmax($scores);
        $raw = (array) $model['raw'];
        $sigma = (float) config('ai.model_lab.outlier_sigma', 4.0);

        $state = $this->columns(WideErrandEncoder::STATE, $point->state, (array) $raw['state_mean'], (array) $raw['state_std'], $sigma);
        $rows = [];
        foreach ($point->candidates as $index => $candidate) {
            $engine = $candidate->objectId === null ? null : ($trace->candidates[$candidate->objectId] ?? null);
            $columns = $this->columns(WideErrandEncoder::candidateNames(), $candidate->features, (array) $raw['cand_mean'], (array) $raw['cand_std'], $sigma);
            $rows[] = [
                'index' => $index,
                'kind' => $candidate->reason,
                'action' => $engine?->candidate->type->name ?? __('t_ai.lab_wait'),
                'reason' => $engine?->candidate->reason ?? '',
                'target' => $engine === null ? '' : $this->target($engine->candidate->parameters),
                'engine_score' => $engine?->score,
                'score' => $scores[$index] ?? null,
                'share' => $shares[$index] ?? null,
                'teacher' => $index === $point->teacherIndex,
                'model' => $index === $pick,
                'columns' => array_values(array_filter($columns, static fn (array $column): bool => $column['value'] != 0.0 || $column['outlier'])),
                'outliers' => count(array_filter($columns, static fn (array $column): bool => $column['outlier'])),
            ];
        }

        $label = static fn (int $index): string => isset($rows[$index]) && $index !== 0 ? $rows[$index]['action'] . ' · ' . $rows[$index]['kind'] : __('t_ai.lab_wait');

        return [
            'profile' => $profile,
            'error' => null,
            'flies' => ErrandPolicyModel::configured($profile) !== null ? 'model' : 'scripted',
            'skipped' => $trace->perception->fleetsaveEligible ? __('t_ai.lab_skip_save') : ($trace->perception->reactionWakeAt !== null ? __('t_ai.lab_skip_wake') : null),
            'engine_selected' => $trace->selected->candidate->type->name . ' · ' . $trace->selected->candidate->reason,
            'engine_candidates' => count($trace->candidates),
            'state' => $state,
            'rows' => $rows,
            'teacher' => $point->teacherIndex,
            'pick' => $pick,
            'teacher_label' => $label($point->teacherIndex),
            'model_pick_label' => $label($pick),
            'model_pick_share' => $shares[$pick] ?? null,
            'agrees' => $pick === $point->teacherIndex,
            'value' => $this->value($raw, $point),
            'checks' => $this->checks($point, $scores, $again, $pick, $state, $rows),
            'milliseconds' => (int) round((microtime(true) - $started) * 1000),
        ];
    }

    /**
     * @param list<string> $names
     * @param list<float> $values
     * @param array<mixed> $mean
     * @param array<mixed> $std
     * @return list<array{name: string, value: float, z: float, outlier: bool}>
     */
    private function columns(array $names, array $values, array $mean, array $std, float $sigma): array
    {
        $columns = [];
        foreach ($values as $i => $value) {
            $average = $mean[$i] ?? 0.0;
            $spread = $std[$i] ?? 1.0;
            $z = ($value - (is_numeric($average) ? (float) $average : 0.0)) / max(1e-9, is_numeric($spread) ? (float) $spread : 1.0);
            $columns[] = ['name' => $names[$i] ?? ('#' . $i), 'value' => $value, 'z' => $z, 'outlier' => abs($z) > $sigma];
        }

        return $columns;
    }

    /**
     * The scores as shares of one: how sure the model is between the rows it could take.
     *
     * @param array<int, float> $scores
     * @return array<int, float>
     */
    private function softmax(array $scores): array
    {
        if ($scores === []) {
            return [];
        }
        $top = max($scores);
        $exp = array_map(static fn (float $score): float => exp($score - $top), $scores);
        $sum = array_sum($exp);

        return array_map(static fn (float $value): float => $value / $sum, $exp);
    }

    /**
     * The value head: what the model expects this account to be worth at season end (ln of score), from its state alone.
     *
     * @param array<string, mixed> $raw
     */
    private function value(array $raw, ChoicePoint $point): float|null
    {
        if (!isset($raw['value.0.weight'], $raw['value.2.weight'])) {
            return null;
        }
        $x = [];
        foreach ($point->state as $i => $value) {
            $x[] = ($value - (float) $raw['state_mean'][$i]) / max(1e-9, (float) $raw['state_std'][$i]);
        }
        foreach (['state_net.0', 'state_net.2', 'value.0', 'value.2'] as $layer) {
            $x = $this->layer($raw, $layer, $x, $layer !== 'value.2');
        }

        return $x[0] ?? null;
    }

    /**
     * @param array<string, mixed> $raw
     * @param list<float> $input
     * @return list<float>
     */
    private function layer(array $raw, string $layer, array $input, bool $relu): array
    {
        $out = [];
        foreach ($raw[$layer . '.weight'] as $o => $weights) {
            $sum = (float) $raw[$layer . '.bias'][$o];
            foreach ($weights as $i => $weight) {
                $sum += $weight * ($input[$i] ?? 0.0);
            }
            $out[] = $relu ? max(0.0, $sum) : $sum;
        }

        return $out;
    }

    /**
     * What "behaves correctly" means for one decision, each a check an operator can read as pass, warn or fail.
     *
     * @param array<int, float> $scores
     * @param array<int, float> $again
     * @param list<array<string, mixed>> $state
     * @param list<array<string, mixed>> $rows
     * @return list<array{name: string, status: string, detail: string}>
     */
    private function checks(ChoicePoint $point, array $scores, array $again, int $pick, array $state, array $rows): array
    {
        $finite = array_filter($scores, static fn (float $score): bool => !is_finite($score)) === [];
        $stateOutliers = array_column(array_filter($state, static fn (array $column): bool => $column['outlier']), 'name');
        $rowOutliers = array_sum(array_column($rows, 'outliers'));
        $picked = $rows[$pick] ?? null;
        $odds = null;
        foreach ($picked['columns'] ?? [] as $column) {
            $odds = $column['name'] === 'odds' ? $column['value'] : $odds;
        }
        $incoming = 0.0;
        foreach ($state as $column) {
            $incoming = $column['name'] === 'incoming_hours' ? $column['value'] : $incoming;
        }
        $saveOffered = array_filter($rows, static fn (array $row): bool => $row['kind'] === 'save') !== [];

        return [
            ['name' => __('t_ai.lab_check_finite'), 'status' => $finite ? 'pass' : 'fail', 'detail' => count($scores) . ' ' . __('t_ai.lab_rows')],
            ['name' => __('t_ai.lab_check_legal'), 'status' => ($point->candidates[$pick]->legal ?? false) ? 'pass' : 'fail', 'detail' => '#' . $pick],
            ['name' => __('t_ai.lab_check_deterministic'), 'status' => $scores === $again ? 'pass' : 'fail', 'detail' => ''],
            ['name' => __('t_ai.lab_check_state_range'), 'status' => $stateOutliers === [] ? 'pass' : 'warn', 'detail' => implode(', ', $stateOutliers)],
            ['name' => __('t_ai.lab_check_row_range'), 'status' => $rowOutliers === 0 ? 'pass' : 'warn', 'detail' => (string) $rowOutliers],
            ['name' => __('t_ai.lab_check_raid_odds'), 'status' => !in_array($picked['kind'] ?? '', ['raid', 'strike'], true) || $odds === null ? 'pass' : ($odds >= 0.5 ? 'pass' : 'warn'), 'detail' => $odds === null ? '' : number_format($odds, 2)],
            ['name' => __('t_ai.lab_check_threat'), 'status' => $incoming > 0 && $saveOffered && ($picked['kind'] ?? '') === 'wait' ? 'warn' : 'pass', 'detail' => $incoming > 0 ? number_format($incoming, 1) . ' h' : ''],
        ];
    }

    /** @param array<string, mixed> $parameters */
    private function target(array $parameters): string
    {
        foreach (['target', 'coordinates', 'destination'] as $key) {
            if (isset($parameters[$key]) && is_scalar($parameters[$key])) {
                return (string) $parameters[$key];
            }
        }
        if (isset($parameters['galaxy'], $parameters['system'], $parameters['position'])) {
            return $parameters['galaxy'] . ':' . $parameters['system'] . ':' . $parameters['position'];
        }

        return '';
    }

    /**
     * @param array<string, mixed> $scenario
     * @return array<string, mixed>
     */
    private function summary(AiProfile $profile, array $scenario): array
    {
        $statuses = array_column($scenario['checks'] ?? [], 'status');

        return [
            'player_id' => $profile->player_id,
            'archetype' => $profile->archetype->name,
            'error' => $scenario['error'] ?? null,
            'flies' => $scenario['flies'] ?? null,
            'skipped' => $scenario['skipped'] ?? null,
            'rows' => count($scenario['rows'] ?? []),
            'teacher' => $scenario['teacher_label'] ?? '-',
            'model' => $scenario['model_pick_label'] ?? '-',
            'share' => $scenario['model_pick_share'] ?? null,
            'agrees' => $scenario['agrees'] ?? null,
            'value' => $scenario['value'] ?? null,
            'checks' => in_array('fail', $statuses, true) ? 'fail' : (in_array('warn', $statuses, true) ? 'warn' : ($statuses === [] ? '-' : 'pass')),
            'milliseconds' => $scenario['milliseconds'] ?? null,
        ];
    }

    /**
     * @param list<array<string, mixed>> $overview
     * @return array{decided: int, agree: int, picks: array<string, int>}
     */
    private function agreement(array $overview): array
    {
        $decided = array_filter($overview, static fn (array $row): bool => $row['agrees'] !== null);
        $picks = [];
        foreach ($decided as $row) {
            $kind = explode(' · ', (string) $row['model'])[1] ?? (string) $row['model'];
            $picks[$kind] = ($picks[$kind] ?? 0) + 1;
        }
        arsort($picks);

        return ['decided' => count($decided), 'agree' => count(array_filter($decided, static fn (array $row): bool => $row['agrees'] === true)), 'picks' => $picks];
    }
}
