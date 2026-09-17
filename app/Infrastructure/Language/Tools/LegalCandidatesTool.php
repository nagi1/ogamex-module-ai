<?php

namespace Modules\AI\Infrastructure\Language\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * The legal candidate actions the native policy already produced, with their native scores
 * and reasons. The list is the lane's own context, so the tool takes no arguments: the model
 * reads it, and the module's validator still re-checks whatever candidate it names.
 *
 * A candidate carries the action type value, its name, the native reason and score — never the
 * parameters or source timestamps, which the brief already strips.
 */
class LegalCandidatesTool implements Tool
{
    /**
     * @param list<array{id:int, action:string, reason:string, score:float}> $candidates
     */
    public function __construct(private readonly array $candidates)
    {
    }

    public function description(): Stringable|string
    {
        return 'Read the legal candidate actions the native policy produced, each with its action name, native score and reason.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Stringable|string
    {
        return (string) json_encode($this->candidates, JSON_THROW_ON_ERROR);
    }
}
