<?php

namespace Modules\AI\Domain\Persona;

use Modules\AI\Enums\AiActivityBand;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiDefenseDoctrine;
use Modules\AI\Enums\AiEconomicRole;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Enums\AiStockpileStrategy;
use Modules\AI\Support\RandomSource;
use OGame\Enums\CharacterClass;

/**
 * Generates the persisted persona dimensions together, so a persona is coherent and stays coherent
 * across distribution edits. The archetype selects a conditional distribution for each dimension,
 * skill shifts the defence distribution the way it shifts what a player has learned, and every
 * draw is deterministic per seed. The percentages are simulation choices, not historical
 * statistics (persona-decomposition.md).
 */
final class AiPersonaFactory
{
    public function __construct(private RandomSource $random) {}

    public function create(AiArchetype $archetype, AiSkillBand $skill, int $seed): AiPersona
    {
        return app()->makeWith(AiPersona::class, [
            'activityBand' => AiActivityBand::from($this->choose($seed, 'activity', $this->activityDistribution($archetype))),
            'defenseDoctrine' => AiDefenseDoctrine::from($this->choose($seed, 'defense', $this->defenseDistribution($archetype, $skill))),
            'stockpileStrategy' => AiStockpileStrategy::from($this->choose($seed, 'stockpile', $this->stockpileDistribution($archetype))),
            'economicRole' => AiEconomicRole::from($this->choose($seed, 'economy', $this->economyDistribution($archetype))),
        ]);
    }

    /**
     * The character class a persona would pick, weighted instead of the old hard mapping. A
     * veteran sticks to the class its archetype leans on; a novice falls back on the safe
     * Collector more often. Once selected it is kept stable unless class changes are implemented.
     */
    public function characterClass(AiArchetype $archetype, AiSkillBand $skill, int $seed): CharacterClass
    {
        return CharacterClass::from($this->choose($seed, 'class', $this->classDistribution($archetype, $skill)));
    }

    /**
     * @param  array<int, float>  $weights  enum value => weight
     */
    public function choose(int $seed, string $context, array $weights): int
    {
        $total = array_sum($weights);
        $draw = $this->random->unitInterval($seed, 'persona:'.$context) * $total;
        $cumulative = 0.0;
        foreach ($weights as $value => $weight) {
            $cumulative += $weight;
            if ($draw < $cumulative) {
                return $value;
            }
        }

        $keys = array_keys($weights);

        return $keys[count($keys) - 1];
    }

    /** @return array<int, float> AiActivityBand value => weight */
    private function activityDistribution(AiArchetype $archetype): array
    {
        return match ($archetype) {
            AiArchetype::Miner, AiArchetype::Hybrid => [AiActivityBand::Casual->value => 10, AiActivityBand::Regular->value => 55, AiActivityBand::Active->value => 30, AiActivityBand::Hardcore->value => 5],
            AiArchetype::Turtle => [AiActivityBand::Casual->value => 10, AiActivityBand::Regular->value => 50, AiActivityBand::Active->value => 35, AiActivityBand::Hardcore->value => 5],
            AiArchetype::Fleeter, AiArchetype::Raider => [AiActivityBand::Casual->value => 5, AiActivityBand::Regular->value => 25, AiActivityBand::Active->value => 55, AiActivityBand::Hardcore->value => 15],
            AiArchetype::Trader => [AiActivityBand::Casual->value => 10, AiActivityBand::Regular->value => 60, AiActivityBand::Active->value => 25, AiActivityBand::Hardcore->value => 5],
            AiArchetype::Casual => [AiActivityBand::Casual->value => 1],
        };
    }

    /** @return array<int, float> AiDefenseDoctrine value => weight */
    private function defenseDistribution(AiArchetype $archetype, AiSkillBand $skill): array
    {
        $weights = match ($archetype) {
            AiArchetype::Miner, AiArchetype::Hybrid => [AiDefenseDoctrine::ProductionShell->value => 40, AiDefenseDoctrine::Minimalist->value => 20, AiDefenseDoctrine::BalancedMixed->value => 15, AiDefenseDoctrine::RocketPlasma->value => 10, AiDefenseDoctrine::Adaptive->value => 8, AiDefenseDoctrine::FodderHeavy->value => 5, AiDefenseDoctrine::Bunker->value => 2],
            AiArchetype::Turtle => [AiDefenseDoctrine::Bunker->value => 30, AiDefenseDoctrine::RocketPlasma->value => 20, AiDefenseDoctrine::BalancedMixed->value => 20, AiDefenseDoctrine::HeavyMixed->value => 10, AiDefenseDoctrine::FodderHeavy->value => 10, AiDefenseDoctrine::Adaptive->value => 10],
            AiArchetype::Fleeter, AiArchetype::Raider => [AiDefenseDoctrine::Minimalist->value => 55, AiDefenseDoctrine::ProductionShell->value => 25, AiDefenseDoctrine::BalancedMixed->value => 10, AiDefenseDoctrine::Adaptive->value => 7, AiDefenseDoctrine::RocketPlasma->value => 3],
            AiArchetype::Trader => [AiDefenseDoctrine::Minimalist->value => 50, AiDefenseDoctrine::ProductionShell->value => 25, AiDefenseDoctrine::BalancedMixed->value => 15, AiDefenseDoctrine::Adaptive->value => 10],
            AiArchetype::Casual => [AiDefenseDoctrine::Minimalist->value => 60, AiDefenseDoctrine::ProductionShell->value => 20, AiDefenseDoctrine::BalancedMixed->value => 15, AiDefenseDoctrine::Adaptive->value => 5],
        };

        return $this->shiftedBySkill($weights, $skill);
    }

    /**
     * A veteran prefers the adaptive wall it has learned to read threats for; a novice leans on
     * the simple, heavy walls it can reason about without a threat model.
     *
     * @param  array<int, float>  $weights  AiDefenseDoctrine value => weight
     * @return array<int, float>
     */
    private function shiftedBySkill(array $weights, AiSkillBand $skill): array
    {
        if ($skill === AiSkillBand::Standard) {
            return $weights;
        }

        $veteran = $skill === AiSkillBand::Veteran;
        foreach ($weights as $value => $weight) {
            if ($veteran) {
                if ($value === AiDefenseDoctrine::Adaptive->value) {
                    $weights[$value] = $weight * 2.0;

                    continue;
                }
                if ($value === AiDefenseDoctrine::FodderHeavy->value || $value === AiDefenseDoctrine::Bunker->value) {
                    $weights[$value] = $weight * 0.5;
                }

                continue;
            }

            if ($value === AiDefenseDoctrine::Adaptive->value) {
                $weights[$value] = $weight * 0.5;

                continue;
            }
            if ($value === AiDefenseDoctrine::FodderHeavy->value || $value === AiDefenseDoctrine::Bunker->value || $value === AiDefenseDoctrine::BalancedMixed->value) {
                $weights[$value] = $weight * 1.5;
            }
        }

        return $weights;
    }

    /** @return array<int, float> AiStockpileStrategy value => weight */
    private function stockpileDistribution(AiArchetype $archetype): array
    {
        return match ($archetype) {
            AiArchetype::Miner, AiArchetype::Hybrid => [AiStockpileStrategy::ScheduledSpender->value => 50, AiStockpileStrategy::GoalSaver->value => 20, AiStockpileStrategy::ImmediateSpender->value => 15, AiStockpileStrategy::CarelessHoarder->value => 10, AiStockpileStrategy::BunkerBanker->value => 5],
            AiArchetype::Turtle => [AiStockpileStrategy::BunkerBanker->value => 40, AiStockpileStrategy::ScheduledSpender->value => 30, AiStockpileStrategy::GoalSaver->value => 15, AiStockpileStrategy::CarelessHoarder->value => 10, AiStockpileStrategy::ImmediateSpender->value => 5],
            AiArchetype::Fleeter, AiArchetype::Raider => [AiStockpileStrategy::FleetSaveBanker->value => 50, AiStockpileStrategy::ScheduledSpender->value => 20, AiStockpileStrategy::GoalSaver->value => 10, AiStockpileStrategy::ImmediateSpender->value => 10, AiStockpileStrategy::CarelessHoarder->value => 10],
            AiArchetype::Trader => [AiStockpileStrategy::ScheduledSpender->value => 40, AiStockpileStrategy::ImmediateSpender->value => 25, AiStockpileStrategy::GoalSaver->value => 20, AiStockpileStrategy::CarelessHoarder->value => 15],
            AiArchetype::Casual => [AiStockpileStrategy::ImmediateSpender->value => 40, AiStockpileStrategy::CarelessHoarder->value => 30, AiStockpileStrategy::ScheduledSpender->value => 20, AiStockpileStrategy::GoalSaver->value => 10],
        };
    }

    /** @return array<int, float> AiEconomicRole value => weight */
    private function economyDistribution(AiArchetype $archetype): array
    {
        return match ($archetype) {
            AiArchetype::Miner, AiArchetype::Hybrid => [AiEconomicRole::SelfSufficient->value => 60, AiEconomicRole::DeutSeller->value => 25, AiEconomicRole::AllianceSupplier->value => 15],
            AiArchetype::Turtle => [AiEconomicRole::SelfSufficient->value => 70, AiEconomicRole::DeutSeller->value => 20, AiEconomicRole::AllianceSupplier->value => 10],
            AiArchetype::Fleeter, AiArchetype::Raider => [AiEconomicRole::DeutBuyer->value => 50, AiEconomicRole::SelfSufficient->value => 35, AiEconomicRole::AllianceSupplier->value => 15],
            AiArchetype::Trader => [AiEconomicRole::ActiveTrader->value => 50, AiEconomicRole::DeutSeller->value => 25, AiEconomicRole::DeutBuyer->value => 25],
            AiArchetype::Casual => [AiEconomicRole::SelfSufficient->value => 85, AiEconomicRole::DeutSeller->value => 15],
        };
    }

    /** @return array<int, float> CharacterClass value => weight */
    private function classDistribution(AiArchetype $archetype, AiSkillBand $skill): array
    {
        $weights = match ($archetype) {
            AiArchetype::Miner, AiArchetype::Hybrid => [CharacterClass::COLLECTOR->value => 60, CharacterClass::DISCOVERER->value => 30, CharacterClass::GENERAL->value => 10],
            AiArchetype::Turtle => [CharacterClass::COLLECTOR->value => 55, CharacterClass::DISCOVERER->value => 20, CharacterClass::GENERAL->value => 25],
            AiArchetype::Fleeter, AiArchetype::Raider => [CharacterClass::GENERAL->value => 70, CharacterClass::DISCOVERER->value => 20, CharacterClass::COLLECTOR->value => 10],
            AiArchetype::Trader => [CharacterClass::DISCOVERER->value => 70, CharacterClass::COLLECTOR->value => 20, CharacterClass::GENERAL->value => 10],
            AiArchetype::Casual => [CharacterClass::COLLECTOR->value => 60, CharacterClass::GENERAL->value => 25, CharacterClass::DISCOVERER->value => 15],
        };

        if ($skill === AiSkillBand::Veteran) {
            $weights[$this->dominantKey($weights)] *= 1.3;
        }
        if ($skill === AiSkillBand::Novice) {
            $weights[CharacterClass::COLLECTOR->value] *= 1.5;
        }

        return $weights;
    }

    /** @param array<int, float> $weights */
    private function dominantKey(array $weights): int
    {
        $keys = array_keys($weights);
        $dominant = $keys[0];
        foreach ($weights as $value => $weight) {
            if ($weight > $weights[$dominant]) {
                $dominant = $value;
            }
        }

        return $dominant;
    }
}
