<?php

namespace Modules\AI\Actions;

use Laravel\Ai\Classification\Boolean;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Modules\AI\Ai\Agents\OgameDoctrineExtractionAgent;
use OGame\Services\ObjectService;
use Throwable;

/**
 * Wiki page to checked doctrine proposals (llm-classifier-everywhere.md, step 5): the classifier screens the page for
 * concrete build, research or fleet advice; the language model proposes entries for the doctrine keys a planner reads;
 * an entry naming an object the host does not have is dropped; the classifier then checks each entry against the
 * sentence it cites. What survives is a proposal for the owner, never a write to resources/doctrine/.
 */
class ProposeAiDoctrineFromSourceAction
{
    /** An entry the classifier is not this sure its source says is not proposed. */
    private const AGREEMENT = 0.7;

    /**
     * @return list<array{archetype: string, key: string, steps: list<array{object: string, value: float}>, quote: string, checked: bool}>
     */
    public function handle(string $page): array
    {
        $screen = app(ReadAiTextAction::class)->handle('wiki-page', $page, [
            'advice' => new Boolean('Does this OGame wiki page give concrete advice on what to build, research or fly, in what order or in what proportion?'),
        ]);
        if ($screen !== null && !$screen->isTrue('advice')) {
            return [];
        }

        try {
            $response = app(OgameDoctrineExtractionAgent::class)->prompt($page, provider: config('ai.language.provider'), model: config('ai.language.model'));
        } catch (Throwable) {
            return [];
        }

        if (!$response instanceof StructuredAgentResponse) {
            return [];
        }

        $proposals = [];
        foreach ((array) ($response['proposals'] ?? []) as $entry) {
            $proposal = $this->checked((array) $entry);
            if ($proposal !== null) {
                $proposals[] = $proposal;
            }
        }

        return $proposals;
    }

    /** @param array<string, mixed> $entry */
    private function checked(array $entry): array|null
    {
        $steps = [];
        foreach ((array) ($entry['steps'] ?? []) as $step) {
            $object = (string) ($step['object'] ?? '');
            try {
                ObjectService::getObjectByMachineName($object);
            } catch (Throwable) {
                return null;
            }

            $steps[] = ['object' => $object, 'value' => (float) ($step['value'] ?? 0)];
        }

        $quote = trim((string) ($entry['quote'] ?? ''));
        $rendered = implode(', ', array_map(static fn (array $step): string => $step['object'] . ' ' . $step['value'], $steps));
        $check = app(ReadAiTextAction::class)->handle('doctrine-check', "Source: {$quote}\nEntry: {$entry['archetype']} {$entry['key']}: {$rendered}", [
            'agrees' => new Boolean('Does the source sentence say what the entry says, without adding to it?'),
        ]);
        if ($steps === [] || $quote === '' || ($check !== null && !$check->isTrue('agrees', self::AGREEMENT))) {
            return null;
        }

        return [
            'archetype' => (string) $entry['archetype'],
            'key' => (string) $entry['key'],
            'steps' => $steps,
            'quote' => $quote,
            // Unchecked means the classifier was off or failed: the owner reads it with that in mind.
            'checked' => $check !== null,
        ];
    }
}
