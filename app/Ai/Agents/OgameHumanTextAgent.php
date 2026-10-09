<?php

namespace Modules\AI\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Strict;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Writes the messages real players send, one act at a time, offline (ai:write-human-texts), so the text classifier is
 * trained and tested on human wording whose label is known by construction instead of on our own authored lines.
 * Zero calls in play: the owner runs it and the output is training data, not a reply.
 */
#[Strict]
class OgameHumanTextAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function __construct(private readonly string $act, private readonly string $meaning, private readonly int $count)
    {
    }

    public function instructions(): Stringable|string
    {
        return "You write chat messages that real OGame players send to each other, the kind found in in-game messages and alliance chat. "
            . "Write {$this->count} different messages that each do exactly this: the writer {$this->meaning}. "
            . 'Vary them like different people would: very short to two sentences, formal and sloppy, calm and angry, shorthand (np, gg, rn, res, pls, thx, nap, acs), lowercase, typos, '
            . 'game detail where natural (coordinates like 2:45:7, fleet sizes, times, planet or alliance names), a few in German, French, Spanish or Polish. '
            . 'Do not repeat a message, do not number them and do not explain them. Messages that only look like another kind of message are worth writing too, '
            . 'but each one must still clearly be this kind. Never include a real person\'s data.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'messages' => $schema->array()->min(1)->max(60)->items($schema->string()->min(1)->max(200))->required(),
        ];
    }
}
