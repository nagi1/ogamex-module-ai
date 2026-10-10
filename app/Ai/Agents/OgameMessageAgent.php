<?php

namespace Modules\AI\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\Strict;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;
use Symfony\Component\Yaml\Yaml;

/**
 * Words one in-game line for one player from the facts of the situation: a leader declaring war, an ACS call, a plea
 * for a truce, a refusal. It decides nothing; the decision was taken by the module's rules and is in the facts.
 */
#[Strict]
class OgameMessageAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function __construct(private readonly string $archetype, private readonly string $maximumCharacters)
    {
    }

    public function instructions(): Stringable|string
    {
        $file = Yaml::parseFile(dirname(__DIR__, 3) . '/resources/behavior/composer.yaml');
        $voice = (string) ($file['voices'][$this->archetype] ?? $this->archetype);

        return strtr((string) $file['instructions'], ['{archetype}' => $this->archetype . ' (' . $voice . ')', '{max}' => $this->maximumCharacters]);
    }

    public function schema(JsonSchema $schema): array
    {
        return ['text' => $schema->string()->min(1)->max(200)->required()];
    }
}
