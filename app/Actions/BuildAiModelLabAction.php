<?php

namespace Modules\AI\Actions;

use Illuminate\Support\Facades\DB;
use JsonException;
use Modules\AI\Domain\Choice\ChoicePoint;
use Modules\AI\Domain\Choice\EconomyChoiceEncoder;
use Modules\AI\Domain\Choice\EconomyPolicyModel;
use Modules\AI\Domain\Choice\ErrandPolicyModel;
use Modules\AI\Domain\Choice\WideErrandEncoder;
use Modules\AI\Domain\Decision\DecisionEngine;
use Modules\AI\Domain\Decision\QueueableBuildingPlanner;
use Modules\AI\Domain\Login\LoginPlans;
use Modules\AI\Domain\Perception\PlayerPerceptionBuilder;
use Modules\AI\Models\AiProfile;
use OGame\Factories\PlayerServiceFactory;
use OGame\Services\ObjectService;
use Throwable;

/**
 * The model lab (config `ai.model_lab`): the two trained models the game can run, asked about every AI account's
 * situation right now, in words an operator reads without knowing how they were trained.
 *
 * The fleet model (`ai.rl.errand_model`) answers "what should my fleet do this login", the economy model
 * (`ai.rl.economy_model`) "what should I build or research next". Each is asked exactly as the game asks it: the same
 * perception, the same planner and the same encoders. It is a dry run: everything happens inside a transaction that is
 * rolled back, and nothing is queued, so opening the page never moves the game.
 */
class BuildAiModelLabAction
{
    /** @var array<string, array<string, mixed>|null> one parse per file and modification time */
    private static array $raw = [];

    public function __construct(
        private PlayerPerceptionBuilder $perceptions,
        private DecisionEngine $engine,
        private PlayerServiceFactory $players,
        private WideErrandEncoder $errands,
        private QueueableBuildingPlanner $planner,
        private EconomyChoiceEncoder $economy,
    ) {
    }

    /** @return array<string, mixed> */
    public function handle(int|null $playerId): array
    {
        $fleetPath = (string) (config('ai.model_lab.fleet_model') ?: config('ai.rl.errand_model'));
        $economyPath = (string) (config('ai.model_lab.economy_model') ?: config('ai.rl.economy_model'));
        $fleet = is_file($fleetPath) ? ErrandPolicyModel::parse((string) file_get_contents($fleetPath)) : null;
        $economy = is_file($economyPath) ? EconomyPolicyModel::parse((string) file_get_contents($economyPath)) : null;

        $accounts = [];
        $detail = null;
        foreach (AiProfile::query()->orderBy('player_id')->get() as $profile) {
            $open = $profile->player_id === $playerId;
            $account = $this->account($profile, $fleet, $fleetPath, $economy, $economyPath, $open);
            $accounts[] = $account;
            $detail = $open ? $account : $detail;
        }

        return [
            'fleet' => $this->modelCard($fleet, $fleetPath, (bool) config('ai.rl.errand_model'), array_column($accounts, 'fleet')),
            'economy' => $this->modelCard($economy, $economyPath, config('ai.rl.policy') === 'model' && (bool) config('ai.rl.economy_model'), array_merge([], ...array_column($accounts, 'economy'))),
            'accounts' => $accounts,
            'detail' => $detail,
        ];
    }

    /**
     * One account: what its fleet and its builds would do now, by the planner and by each model.
     *
     * @return array<string, mixed>
     */
    private function account(AiProfile $profile, ErrandPolicyModel|null $fleet, string $fleetPath, EconomyPolicyModel|null $economy, string $economyPath, bool $open): array
    {
        $plans = app(LoginPlans::class);
        $result = ['player_id' => $profile->player_id, 'archetype' => $profile->archetype->name, 'error' => null, 'fleet' => null, 'economy' => []];
        DB::beginTransaction();
        try {
            $plans->reset();
            $player = $this->players->make($profile->player_id, true);
            if ($player->planets->all() === []) {
                return [...$result, 'error' => __('t_ai.lab_no_planets')];
            }
            if ($fleet !== null) {
                $perception = $this->perceptions->build($profile->player_id, 60);
                $trace = $this->engine->decide($profile, $perception, 'model-lab:' . $profile->player_id);
                $point = $this->errands->point($profile->player_id, $player, $trace, 'model-lab:' . $profile->player_id);
                $result['fleet'] = $this->fleetChoice($profile, $fleet, $fleetPath, $point, $open);
                $result['fleet']['skipped'] = $perception->fleetsaveEligible ? __('t_ai.lab_skip_save') : ($perception->reactionWakeAt !== null ? __('t_ai.lab_skip_wake') : null);
            }
            if ($economy !== null) {
                $steps = $this->planner->steps($profile->player_id, $player);
                $state = $this->economy->account($profile, $player)['state'];
                foreach ($this->planner->choiceSets($profile->player_id, $steps, $player) as $set) {
                    $point = $this->economy->point($profile->player_id, $state, $set, 'model-lab:' . $profile->player_id);
                    $result['economy'][] = $this->economyChoice($economy, $economyPath, $point, $set['planet']->getPlanetName(), $open);
                }
            }
        } catch (Throwable $exception) {
            $result['error'] = $exception->getMessage();
        } finally {
            $plans->reset();
            DB::rollBack();
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function fleetChoice(AiProfile $profile, ErrandPolicyModel $model, string $path, ChoicePoint $point, bool $open): array
    {
        $scores = $model->scores($point);
        $pick = $model->best($point);
        $label = fn (int $row): string => $this->errandLabel($point->candidates[$row]->reason ?? 'wait');
        $choice = [
            'planner' => $label($point->teacherIndex),
            'model' => $label($pick),
            'agrees' => $pick === $point->teacherIndex,
            'in_game' => ErrandPolicyModel::configured($profile) !== null ? 'model' : 'planner',
        ];
        if (!$open) {
            return $choice;
        }

        $raw = (array) $this->raw($path);
        $state = array_combine(WideErrandEncoder::STATE, $point->state);
        $unusual = $this->unusual(WideErrandEncoder::STATE, $point->state, (array) ($raw['state_mean'] ?? []), (array) ($raw['state_std'] ?? []));

        return [...$choice,
            'facts' => array_values(array_filter([
                $state['incoming_hours'] > 0 ? __('t_ai.lab_fact_incoming', ['hours' => number_format($state['incoming_hours'], 1)]) : __('t_ai.lab_fact_no_incoming'),
                __('t_ai.lab_fact_slots', ['slots' => (int) round($state['fleet_slots_free'])]),
                $state['warship_value'] > 0 ? __('t_ai.lab_fact_warships') : __('t_ai.lab_fact_no_warships'),
                $state['has_moon'] > 0 ? __('t_ai.lab_fact_moon') : null,
            ])),
            'options' => $this->options($scores, $pick, $point->teacherIndex, $label),
            'checks' => $this->checks($scores, $model->scores($point), $point->candidates[$pick]->legal, $unusual),
        ];
    }

    /** @return array<string, mixed> */
    private function economyChoice(EconomyPolicyModel $model, string $path, ChoicePoint $point, string $planet, bool $open): array
    {
        $scores = $model->scores($point);
        $pick = $model->choose($point);
        $label = fn (int $row): string => $this->objectLabel($point, $row);
        $choice = [
            'where' => $point->isResearch() ? __('t_ai.lab_where_research') : __('t_ai.lab_where_planet', ['planet' => $planet]),
            'planner' => $label($point->teacherIndex),
            'model' => $label($pick),
            'agrees' => $pick === $point->teacherIndex,
        ];
        if (!$open) {
            return $choice;
        }

        $raw = (array) $this->raw($path);
        $columns = $model->columns($point);
        $names = [...EconomyPolicyModel::STATE, ...EconomyPolicyModel::ACCOUNT];
        $state = array_combine($names, $columns['state']);
        $unusual = $this->unusual($names, $columns['state'], (array) ($raw['state_mean'] ?? []), (array) ($raw['state_std'] ?? []));

        return [...$choice,
            'facts' => [
                __('t_ai.lab_fact_stock', ['metal' => $this->amount(expm1($state['pl_metal'])), 'crystal' => $this->amount(expm1($state['pl_crystal'])), 'deuterium' => $this->amount(expm1($state['pl_deuterium']))]),
                __('t_ai.lab_fact_energy', ['percent' => (int) round($state['pl_factor'] * 100)]),
                __('t_ai.lab_fact_fields', ['percent' => (int) round($state['pl_fields_used'] * 100)]),
                __('t_ai.lab_fact_rank', ['percent' => max(1, (int) round($state['rank'] * 100))]),
            ],
            'options' => array_slice($this->options($scores, $pick, $point->teacherIndex, $label), 0, 8),
            'alone' => $label($model->choose($point, false)),
            'checks' => $this->checks($scores, $model->scores($point), $point->candidates[$pick]->legal, $unusual),
        ];
    }

    /**
     * Every option the model weighed, best liked first, as a share of 100%.
     *
     * @param array<int, float> $scores
     * @param callable(int): string $label
     * @return list<array{label: string, share: float, model: bool, planner: bool}>
     */
    private function options(array $scores, int $pick, int $planner, callable $label): array
    {
        $options = [];
        if ($scores === []) {
            return $options;
        }
        $top = max($scores);
        $sum = array_sum(array_map(static fn (float $score): float => exp($score - $top), $scores));
        foreach ($scores as $row => $score) {
            $options[] = ['label' => $label($row), 'share' => exp($score - $top) / $sum, 'model' => $row === $pick, 'planner' => $row === $planner];
        }
        usort($options, static fn (array $a, array $b): int => $b['share'] <=> $a['share']);

        return $options;
    }

    /**
     * The plain checks under each answer.
     *
     * @param array<int, float> $scores
     * @param array<int, float> $again
     * @param list<string> $unusual
     * @return list<array{ok: bool, text: string}>
     */
    private function checks(array $scores, array $again, bool $allowed, array $unusual): array
    {
        return [
            ['ok' => $allowed, 'text' => $allowed ? __('t_ai.lab_check_allowed') : __('t_ai.lab_check_waits')],
            ['ok' => $scores === $again && array_filter($scores, static fn (float $score): bool => !is_finite($score)) === [], 'text' => __('t_ai.lab_check_same')],
            ['ok' => $unusual === [], 'text' => $unusual === [] ? __('t_ai.lab_check_familiar') : __('t_ai.lab_check_unfamiliar', ['what' => implode(', ', $unusual)])],
        ];
    }

    /**
     * The plain names of the inputs that sit far outside anything the model saw in training.
     *
     * @param list<string> $names
     * @param list<float> $values
     * @param array<mixed> $mean
     * @param array<mixed> $std
     * @return list<string>
     */
    private function unusual(array $names, array $values, array $mean, array $std): array
    {
        $sigma = (float) config('ai.model_lab.outlier_sigma', 4.0);
        $found = [];
        foreach ($values as $i => $value) {
            $average = is_numeric($mean[$i] ?? null) ? (float) $mean[$i] : 0.0;
            $spread = is_numeric($std[$i] ?? null) ? (float) $std[$i] : 1.0;
            if (abs(($value - $average) / max(1e-9, $spread)) > $sigma) {
                $found[] = $this->words('t_ai.lab_input_' . $names[$i], $names[$i]);
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * @param array<mixed> $choices
     * @return array<string, mixed>
     */
    private function modelCard(ErrandPolicyModel|EconomyPolicyModel|null $model, string $path, bool $inGame, array $choices): array
    {
        $choices = array_values(array_filter($choices, is_array(...)));

        return [
            'loaded' => $model !== null,
            'in_game' => $inGame && $model !== null,
            'file' => $path === '' ? '-' : basename(dirname($path)) . '/' . basename($path),
            'asked' => count($choices),
            'agree' => count(array_filter($choices, static fn (array $choice): bool => ($choice['agrees'] ?? false) === true)),
        ];
    }

    private function errandLabel(string $kind): string
    {
        return $this->words('t_ai.lab_do_' . $kind, $kind);
    }

    private function words(string $key, string $fallback): string
    {
        $text = __($key);

        return $text === $key ? $fallback : $text;
    }

    private function objectLabel(ChoicePoint $point, int $row): string
    {
        $id = $point->candidates[$row]->objectId ?? null;
        if ($id === null) {
            return __('t_ai.lab_do_wait');
        }
        $title = ObjectService::getObjectById($id)->title;

        return $point->candidates[$row]->legal ? $title : __('t_ai.lab_save_for', ['what' => $title]);
    }

    private function amount(float $value): string
    {
        return match (true) {
            $value >= 1e9 => number_format($value / 1e9, 1) . 'B',
            $value >= 1e6 => number_format($value / 1e6, 1) . 'M',
            $value >= 1e3 => number_format($value / 1e3, 1) . 'k',
            default => number_format($value, 0),
        };
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
}
