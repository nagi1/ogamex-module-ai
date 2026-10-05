<?php

namespace Modules\AI\Domain\Choice;

use Modules\AI\Domain\Decision\DecisionTrace;
use Modules\AI\Domain\Decision\ScoredCandidate;
use Modules\AI\Enums\AiCandidateActionType;
use OGame\Services\PlayerService;

/**
 * Turns a login's errand decision (which of the engine's candidate actions the account spends this login on: a raid, a
 * probe, an expedition, a colony, a save, a ferry, a recycle, the shipyard, or nothing) into numbers a policy can rank,
 * in the same schema as the economy choices. The engine already ranks every action the situation offers with the same
 * five features, so the row is that action's type, those features and its score; the mission's target stays the
 * planner's. No object is named: the action types are the engine's own enum.
 */
class ErrandChoiceEncoder
{
    private const MAX_ROWS = 32;

    public function __construct(private EconomyChoiceEncoder $economy)
    {
    }

    /** @return list<string> */
    public static function candidateNames(): array
    {
        return [
            ...array_map(static fn (AiCandidateActionType $type): string => 'e_type_' . strtolower($type->name), AiCandidateActionType::cases()),
            'e_resource_need', 'e_safety', 'e_target_confidence', 'e_travel_cost', 'e_recovery', 'e_score', 'e_rank',
        ];
    }

    /**
     * @param list<float> $account
     */
    public function point(int $playerId, array $account, PlayerService $player, DecisionTrace $trace, string $key): ChoicePoint
    {
        $names = EconomyChoiceEncoder::candidateNames();
        $wait = array_fill(0, count($names), 0.0);
        $wait[0] = 1.0;
        $wait[array_search('legal', $names, true)] = 1.0;
        $wait[array_search('e_type_' . strtolower(AiCandidateActionType::DoNothing->name), $names, true)] = 1.0;

        // Row 0 is waiting, as in every kind: here that is the quiet login, so the engine's DoNothing is that row.
        $teacher = $trace->selected->candidate->type === AiCandidateActionType::DoNothing ? 0 : null;
        $candidates = [app()->makeWith(ChoiceCandidate::class, ['objectId' => null, 'pass' => null, 'reason' => 'wait', 'legal' => true, 'features' => $wait])];
        $ranked = array_values(array_filter($trace->candidates, static fn (ScoredCandidate $scored): bool => $scored->candidate->type !== AiCandidateActionType::DoNothing));
        foreach (array_slice($ranked, 0, self::MAX_ROWS - 1) as $index => $scored) {
            $candidates[] = app()->makeWith(ChoiceCandidate::class, [
                'objectId' => $scored->candidate->type->value,
                'pass' => null,
                'reason' => $scored->candidate->reason,
                'legal' => true,
                'features' => $this->features($names, $scored, $index),
            ]);
            $teacher = $scored === $trace->selected ? count($candidates) - 1 : $teacher;
        }

        $planet = $player->planets->current();
        $economy = $this->economy->planetState($planet, false);
        $widthAfterKinds = count(YardChoiceEncoder::STATE);
        $base = array_slice($economy, 0, count($economy) - 2 - $widthAfterKinds);

        return app()->makeWith(ChoicePoint::class, [
            'playerId' => $playerId,
            'planetId' => $planet->getPlanetId(),
            'kind' => ChoicePoint::ERRAND,
            'state' => [...$account, ...$base, 0.0, 1.0, ...array_fill(0, $widthAfterKinds, 0.0)],
            'candidates' => $candidates,
            'teacherIndex' => $teacher ?? 0,
            'key' => $key,
        ]);
    }

    /**
     * @param list<string> $names
     * @return list<float>
     */
    private function features(array $names, ScoredCandidate $scored, int $index): array
    {
        $features = array_fill(0, count($names), 0.0);
        $set = static function (string $name, float $value) use (&$features, $names): void {
            $features[array_search($name, $names, true)] = $value;
        };

        $set('legal', 1.0);
        $set('teacher_ok', 1.0);
        $set('spendable', 1.0);
        $set('order', ($index + 1) / self::MAX_ROWS);
        $set('e_type_' . strtolower($scored->candidate->type->name), 1.0);
        $set('e_resource_need', (float) $scored->candidate->features['resource_need']);
        $set('e_safety', (float) $scored->candidate->features['safety']);
        $set('e_target_confidence', (float) $scored->candidate->features['target_confidence']);
        $set('e_travel_cost', (float) $scored->candidate->features['travel_cost']);
        $set('e_recovery', (float) $scored->candidate->features['recovery']);
        $set('e_score', $this->economy->signedLog($scored->score));
        $set('e_rank', ($index + 1) / self::MAX_ROWS);

        return $features;
    }
}
