<?php

namespace Modules\AI\Actions;

use Modules\AI\Enums\AiConversationReplyState;
use Modules\AI\Enums\AiSocialExchangeType;
use Modules\AI\Enums\AiSocialResponse;
use Modules\AI\Enums\AiSocialTerm;
use Modules\AI\Models\AiConversationReply;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiRelationship;
use Modules\AI\Models\AiSocialExchange;
use Illuminate\Support\Str;
use Modules\AI\Support\RandomSource;
use Symfony\Component\Yaml\Yaml;

class BuildAuthoredSocialReplyAction
{
    private const REPETITION_COOLDOWN_DELIVERIES = 2;

    private const FILE = '/resources/behavior/social-lines.yaml';

    public const VOICES = '/resources/behavior/social-voices.yaml';

    public function __construct(private RandomSource $randomSource)
    {
    }

    public function handle(AiSocialExchange $exchange, AiProfile $profile): string|null
    {
        if ($exchange->response === null) {
            return null;
        }

        $lines = $this->replyLines($exchange->type, $exchange->response, $this->stanceFor($exchange), $profile);
        if ($lines === []) {
            return null;
        }

        $eligibleLines = $this->withoutRecentDeliveredLines($exchange, $lines);
        $index = (int) floor($this->randomSource->unitInterval($profile->random_seed, 'social-exchange:' . $exchange->id . ':' . $exchange->revision) * count($eligibleLines));
        $line = $eligibleLines[$index];
        $amount = $exchange->response_terms[AiSocialTerm::Amount->value] ?? null;

        return is_int($amount) || is_float($amount) ? str_replace('{amount}', (string) $amount, $line) : $line;
    }

    /**
     * The most specific key the file has for this exchange, its response and the stance, in the persona's voice first
     * and the default voice after (resources/behavior/social-lines.yaml).
     *
     * @return list<string>
     */
    private function replyLines(AiSocialExchangeType $type, AiSocialResponse $response, string $stance, AiProfile $profile): array
    {
        $file = $this->lines();
        $exchange = Str::snake($type->name);
        $keys = ["{$exchange}." . strtolower($response->name) . ".{$stance}", "{$exchange}." . strtolower($response->name), $exchange];

        foreach ([(array) ($file['voices'][strtolower((string) $profile->archetype?->name)] ?? []), (array) ($file['default'] ?? [])] as $voice) {
            foreach ($keys as $key) {
                if (($voice[$key] ?? []) !== []) {
                    return array_values((array) $voice[$key]);
                }
            }
        }

        return [];
    }

    /**
     * Read per reply, not cached: a reply is rare next to a session, and a reviewed voice file takes effect at once.
     *
     * @return array<string, mixed>
     */
    private function lines(): array
    {
        $root = dirname(__DIR__, 2);
        $file = Yaml::parseFile($root . self::FILE);
        $voices = is_file($root . self::VOICES) ? Yaml::parseFile($root . self::VOICES) : [];

        return ['default' => (array) (is_array($file) ? ($file['default'] ?? []) : []), 'voices' => is_array($voices) ? $voices : []];
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private function withoutRecentDeliveredLines(AiSocialExchange $exchange, array $lines): array
    {
        $recentLines = AiConversationReply::query()
            ->where('player_id', $exchange->player_id)
            ->where('counterparty_player_id', $exchange->counterparty_player_id)
            ->where('state', AiConversationReplyState::Delivered)
            ->latest('delivered_at')
            ->limit(self::REPETITION_COOLDOWN_DELIVERIES)
            ->pluck('message')
            ->all();
        $eligibleLines = array_values(array_filter($lines, fn (string $line): bool => !in_array($line, $recentLines, true)));

        return $eligibleLines === [] ? $lines : $eligibleLines;
    }

    /**
     * A coarse stance for wording only: the same decision to a friend and a foe is worded
     * differently, but the decision itself is never changed here. Thresholds mirror the
     * cognition gates — threat 0.75 is the refuse line, trust 0.5 the forgiveness floor.
     */
    private function stanceFor(AiSocialExchange $exchange): string
    {
        $relationship = AiRelationship::query()
            ->where('player_id', $exchange->player_id)
            ->where('other_player_id', $exchange->counterparty_player_id)
            ->first();

        if ((float) $relationship?->threat >= 0.75) {
            return 'foe';
        }

        if ((float) $relationship?->trust >= 0.5) {
            return 'friend';
        }

        return 'neutral';
    }
}
