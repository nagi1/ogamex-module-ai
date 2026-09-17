<?php

namespace Modules\AI\Infrastructure\Language\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Modules\AI\Models\AiCampaign;
use Stringable;

/**
 * The module's own campaign state, read on demand: phase, window and the open/completed
 * stronghold counts for the one campaign the consultation is about.
 *
 * The tool is scoped to the campaign the lane is consulting on, so the model can never reach
 * another campaign's records; it reads and never mutates, and the module's deterministic
 * validator still decides what to do with whatever the model proposes.
 */
class CampaignFactsTool implements Tool
{
    public function __construct(private readonly int $campaignId)
    {
    }

    public function description(): Stringable|string
    {
        return 'Read the campaign state for one campaign: its phase, start and end, and its open and completed stronghold counts.';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['campaign_id' => $schema->integer()->min(1)->required()];
    }

    public function handle(Request $request): Stringable|string
    {
        $arguments = $request->validate(['campaign_id' => ['required', 'integer', 'min:1']]);
        $campaignId = (int) $arguments['campaign_id'];

        // The lane only knows the campaign it is consulting on; any other id is not this
        // tool's fact to hand over, so it answers empty rather than reading sideways.
        if ($campaignId !== $this->campaignId) {
            return '{}';
        }

        $campaign = AiCampaign::query()->find($this->campaignId);

        if ($campaign === null) {
            return '{}';
        }

        return (string) json_encode([
            'id' => $campaign->id,
            'state' => $campaign->state->name,
            'starts_at' => $campaign->starts_at?->toIso8601String(),
            'ends_at' => $campaign->ends_at?->toIso8601String(),
            'open_objectives' => $campaign->objectives()->whereNull('completed_at')->count(),
            'completed_objectives' => $campaign->objectives()->whereNotNull('completed_at')->count(),
        ], JSON_THROW_ON_ERROR);
    }
}
