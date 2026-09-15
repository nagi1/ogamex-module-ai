<?php

namespace Modules\AI\Infrastructure\Cognition;

use Illuminate\Support\Facades\Log;
use Modules\AI\Contracts\AffectEngine;
use Modules\AI\Domain\Cognition\AffectAppraisal;
use Modules\AI\Domain\Cognition\ObservedStimulus;
use Modules\AI\Enums\AiAffectEmotion;

/**
 * Appraises a stimulus through the FAtiMA driver, falling back to the native engine.
 *
 * The adapter translates: it turns the stimulus into the signed OCC values the
 * authored rules bind, perceives one event, and maps the returned OCC emotion back
 * onto the module's taxonomy. It deliberately supplies the raw stimulus dimensions
 * rather than the native engine's persona and trust weights, because the driver's
 * appraisal rules are where persona weighting belongs; reproducing the module's
 * arithmetic here would leave the driver with nothing of its own to contribute.
 *
 * The module's taxonomy has three emotions, so an OCC emotion outside it is declined
 * rather than approximated. Approximating `Joy` onto `Gratitude` would invent a
 * meaning the module never recorded.
 */
class FatimaAffectEngine implements AffectEngine
{
    private const ACTION_AID = 'Aid';

    private const ACTION_HARM = 'Harm';

    private const ACTION_THREATEN = 'Threaten';

    /**
     * Only these three OCC emotions have a module counterpart. Fear is a prospect
     * emotion and Anger/Gratitude are attribution ones, which is why the scenario
     * needs both a well-being rule pair and a goal.
     */
    private const EMOTIONS = [
        'Anger' => AiAffectEmotion::Anger,
        'Fear' => AiAffectEmotion::Fear,
        'Gratitude' => AiAffectEmotion::Gratitude,
    ];

    public function __construct(
        private readonly AffectEngine $fallback,
        private readonly FatimaCognitionSession $session,
    ) {
    }

    public function appraiseObservedEvent(ObservedStimulus $stimulus): AffectAppraisal
    {
        return $this->appraise($stimulus) ?? $this->fallback->appraiseObservedEvent($stimulus);
    }

    private function appraise(ObservedStimulus $stimulus): AffectAppraisal|null
    {
        $branch = $this->branch($stimulus);
        $event = sprintf(
            'Event(Action-End, %s, %s, %s)',
            $this->counterparty(),
            $branch->action,
            $stimulus->archetype->name,
        );

        $state = $this->session->appraise($stimulus->archetype, $event, [
            $this->belief('StimulusDesirability') => $this->format($branch->desirability),
            $this->belief('StimulusThreat') => $this->format($branch->threat),
        ]);

        if ($state === null || $state['emotions'] === []) {
            return null;
        }

        return $this->appraisal($state['mood'], $state['emotions'][0]);
    }

    private function appraisal(float $mood, FatimaEmotion $emotion): AffectAppraisal|null
    {
        $mapped = self::EMOTIONS[$emotion->type] ?? null;

        if ($mapped === null) {
            Log::info('The FAtiMA driver appraised an emotion the module cannot represent; using the native appraisal.', [
                'emotion' => $emotion->type,
            ]);

            return null;
        }

        $intensity = $this->bounded($emotion->intensity);

        return app()->makeWith(AffectAppraisal::class, [
            'emotion' => $mapped,
            'intensity' => $intensity,
            'mood' => $mood,
            'driverEmotion' => $mapped,
            'driverIntensity' => $intensity,
        ]);
    }

    /**
     * Mirrors the native engine's branch selection so both paths answer the same
     * question, then signs the value the way OCC defines it: a desirable event is
     * positive, and a threat lowers a goal's success probability.
     */
    private function branch(ObservedStimulus $stimulus): FatimaStimulusBranch
    {
        if ($stimulus->aid > $stimulus->harm) {
            return $this->branchFor(self::ACTION_AID, $stimulus->aid, 0.0);
        }

        if ($stimulus->threat > $stimulus->harm) {
            return $this->branchFor(self::ACTION_THREATEN, 0.0, -$stimulus->threat);
        }

        return $this->branchFor(self::ACTION_HARM, -$stimulus->harm, 0.0);
    }

    private function branchFor(string $action, float $desirability, float $threat): FatimaStimulusBranch
    {
        return app()->makeWith(FatimaStimulusBranch::class, [
            'action' => $action,
            'desirability' => $desirability,
            'threat' => $threat,
        ]);
    }

    private function belief(string $property): string
    {
        return sprintf('%s(SELF, %s)', $property, $this->counterparty());
    }

    private function counterparty(): string
    {
        return (string) config('ai.cognition.fatima.counterparty', 'Other');
    }

    private function format(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.');
    }

    private function bounded(float $intensity): float
    {
        $ceiling = (float) config('ai.cognition.fatima.intensity_ceiling', 1.0);

        return min($ceiling, max(0, $intensity));
    }
}
