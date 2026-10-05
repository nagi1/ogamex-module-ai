<?php

namespace Modules\AI\Domain\Choice;

use Modules\AI\Models\AiProfile;
use Modules\AI\Support\AiClock;

/**
 * Appends every economy choice point to a JSON Lines file for training (plan/rl/training-plan.md, section
 * 3): the state, every candidate row, what the host allows, what the planner chose, what was played and
 * the account's value, which is all imitation and reinforcement learning need. Training writes nothing to
 * the database. A sidecar `<file>.schema.json` names every feature.
 */
class ChoiceRecorder
{
    /** @var resource|null */
    private static $file = null;

    private static ?string $path = null;

    public function __construct(private AiClock $clock)
    {
    }

    public function enabled(): bool
    {
        return $this->path() !== null;
    }

    public function record(ChoicePoint $point, AiProfile $profile, int $chosen, string $policy, bool $learner, float $value): void
    {
        $file = $this->file();
        if ($file === null) {
            return;
        }

        fwrite($file, json_encode([
            'v' => EconomyChoiceEncoder::VERSION,
            't' => $this->clock->now()->getTimestamp(),
            'seed' => (int) config('ai.rl.seed', 0),
            'player' => $point->playerId,
            'planet' => $point->planetId,
            'archetype' => $profile->archetype->name,
            'kind' => $point->kind,
            'state' => $point->state,
            'cands' => array_map(static fn (ChoiceCandidate $candidate): array => $candidate->features, $point->candidates),
            'legal' => array_map(static fn (ChoiceCandidate $candidate): bool => $candidate->legal, $point->candidates),
            'objects' => array_map(static fn (ChoiceCandidate $candidate): ?int => $candidate->objectId, $point->candidates),
            'teacher' => $point->teacherIndex,
            'chosen' => $chosen,
            'policy' => $policy,
            'learner' => $learner,
            'value' => $value,
        ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION) . "\n");
    }

    private function path(): ?string
    {
        $configured = config('ai.rl.record');

        return is_string($configured) && $configured !== '' ? str_replace('{pid}', (string) getmypid(), $configured) : null;
    }

    /** @return resource|null */
    private function file()
    {
        $path = $this->path();
        if ($path === null) {
            return;
        }

        if (self::$file !== null && self::$path === $path) {
            return self::$file;
        }

        @mkdir(dirname($path), 0775, true);
        $file = fopen($path, 'ab');
        if ($file === false) {
            return;
        }

        file_put_contents($path . '.schema.json', json_encode([
            'version' => EconomyChoiceEncoder::VERSION,
            'state' => EconomyChoiceEncoder::stateNames(),
            'candidate' => EconomyChoiceEncoder::candidateNames(),
        ], JSON_PRETTY_PRINT));

        self::$path = $path;

        return self::$file = $file;
    }
}
