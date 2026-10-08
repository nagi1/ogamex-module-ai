<?php

namespace Modules\AI\Actions;

use Laravel\Ai\Classification\Boolean;
use Modules\AI\Models\AiProfile;

/**
 * The last read of a line before a human sees it (llm-classifier-everywhere.md, step 1): one line that reads like a
 * bot, or that leaks the module's machinery ("threat 0.8", "grievance", "policy"), outs an account for good. A line to
 * an AI account is never read, and with the classifier off every line passes, as before.
 */
class GuardAiOutboundTextAction
{
    /** A strong suspicion only: plain short game talk often reads a little flat, and silence is costly too. */
    private const BOT_THRESHOLD = 0.8;

    private const LEAK_THRESHOLD = 0.5;

    /** @param list<int> $recipientIds */
    public function passes(string $text, array $recipientIds): bool
    {
        if (AiProfile::query()->whereIn('player_id', $recipientIds)->count() >= count(array_unique($recipientIds))) {
            return true;
        }

        return $this->reads($text);
    }

    /** Whether the classifier lets the line through; with it off, or failing, every line does. */
    public function reads(string $text): bool
    {
        $reading = app(ReadAiTextAction::class)->handle('outbound', $text, [
            'bot' => new Boolean('Would an OGame player reading this in the in-game messenger suspect a bot or a fill-in template wrote it?'),
            'leak' => new Boolean('Does it mention machinery a player would never say: numeric scores such as "threat 0.8", words like grievance score, policy, module, model, prompt, AI or system?'),
        ]);

        return $reading === null || (!$reading->isTrue('bot', self::BOT_THRESHOLD) && !$reading->isTrue('leak', self::LEAK_THRESHOLD));
    }
}
