<?php

namespace Modules\AI\Actions;

use Illuminate\Support\Carbon;
use Modules\AI\Models\AiRelationship;
use Symfony\Component\Yaml\Yaml;

/**
 * Lets a quiet grudge go (grudges.yaml): threat, and trust or affinity below zero, halve every half-life once the grace
 * after the last interaction has run out. Without it a raid was held at full weight for the life of the server, so
 * every account a club ever fought stayed an enemy and no club could form across an old feud.
 */
class FadeAiGrudgesAction
{
    private const FILE = '/resources/behavior/grudges.yaml';

    public function handle(): int
    {
        $path = dirname(__DIR__, 2) . self::FILE;
        $policy = is_file($path) ? Yaml::parseFile($path) : null;
        if (!is_array($policy) || !isset($policy['grace_hours'], $policy['half_life_hours'], $policy['floor'])) {
            return 0;
        }

        $grace = (float) $policy['grace_hours'];
        $faded = 0;
        AiRelationship::query()
            ->where(static fn ($query) => $query->where('threat', '>', 0)->orWhere('trust', '<', 0)->orWhere('affinity', '<', 0))
            ->where('last_interaction_at', '<=', now()->subMinutes((int) round($grace * 60)))
            ->each(function (AiRelationship $relationship) use ($grace, $policy, &$faded): void {
                $faded += $this->fade($relationship, $grace, (float) $policy['half_life_hours'], (float) $policy['floor']);
            });

        return $faded;
    }

    private function fade(AiRelationship $relationship, float $grace, float $halfLife, float $floor): int
    {
        // The clock runs from the later of the grace's end and the last fade, so a pass never fades the same hours twice.
        $graceEnds = Carbon::parse($relationship->last_interaction_at)->addMinutes((int) round($grace * 60));
        $since = $relationship->updated_at !== null && $relationship->updated_at->greaterThan($graceEnds) ? $relationship->updated_at : $graceEnds;
        $hours = $since->diffInMinutes(now()) / 60;
        if ($hours <= 0) {
            return 0;
        }

        $factor = 0.5 ** ($hours / $halfLife);
        $relationship->update([
            'threat' => $this->faded((float) $relationship->threat, $factor, $floor),
            'trust' => $this->faded(min(0.0, (float) $relationship->trust), $factor, $floor) + max(0.0, (float) $relationship->trust),
            'affinity' => $this->faded(min(0.0, (float) $relationship->affinity), $factor, $floor) + max(0.0, (float) $relationship->affinity),
            'revision' => $relationship->revision + 1,
        ]);

        return 1;
    }

    private function faded(float $score, float $factor, float $floor): float
    {
        $left = $score * $factor;

        return abs($left) < $floor ? 0.0 : $left;
    }
}
