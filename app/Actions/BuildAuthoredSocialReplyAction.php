<?php

namespace Modules\AI\Actions;

use Modules\AI\Enums\AiConversationReplyState;
use Modules\AI\Enums\AiSocialExchangeType;
use Modules\AI\Enums\AiSocialReplyLocale;
use Modules\AI\Enums\AiSocialResponse;
use Modules\AI\Enums\AiSocialTerm;
use Modules\AI\Models\AiConversationReply;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiSocialExchange;
use Modules\AI\Support\AiProfileSettings;
use Modules\AI\Support\RandomSource;

class BuildAuthoredSocialReplyAction
{
    private const REPETITION_COOLDOWN_DELIVERIES = 2;

    public function __construct(private RandomSource $randomSource)
    {
    }

    public function handle(AiSocialExchange $exchange, AiProfile $profile): string|null
    {
        if ($exchange->response === null) {
            return null;
        }

        $locale = AiProfileSettings::socialReplyLocale($profile);
        $lines = $this->replyLines($exchange->type, $exchange->response, $locale);
        $eligibleLines = $this->withoutRecentDeliveredLines($exchange, $lines);
        $index = (int) floor($this->randomSource->unitInterval($profile->random_seed, 'social-exchange:' . $exchange->id . ':' . $exchange->revision . ':' . $locale->value) * count($eligibleLines));
        $line = $eligibleLines[$index];
        $amount = $exchange->response_terms[AiSocialTerm::Amount->value] ?? null;

        return is_int($amount) || is_float($amount) ? str_replace('{amount}', (string) $amount, $line) : $line;
    }

    /** @return list<string> */
    private function replyLines(AiSocialExchangeType $type, AiSocialResponse $response, AiSocialReplyLocale $locale): array
    {
        return match ($locale) {
            AiSocialReplyLocale::English => match ($type) {
                AiSocialExchangeType::HelpRequest => $this->helpReplyLines($response),
                AiSocialExchangeType::Apology => $this->apologyReplyLines($response),
                AiSocialExchangeType::Greeting => $this->greetingReplyLines(),
                AiSocialExchangeType::Thanks => $this->thanksReplyLines(),
                AiSocialExchangeType::TradeOffer => $this->tradeReplyLines($response),
                AiSocialExchangeType::CeasefireRequest => $this->ceasefireReplyLines($response),
                AiSocialExchangeType::Warning => $this->warningReplyLines($response),
                AiSocialExchangeType::CooperationRequest => $this->cooperationReplyLines($response),
                AiSocialExchangeType::CompensationOffer => $this->compensationReplyLines($response),
            },
            AiSocialReplyLocale::Arabic => $this->arabicReplyLines($type, $response),
        };
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
     * @return list<string>
     */
    private function helpReplyLines(AiSocialResponse $response): array
    {
        return match ($response) {
            AiSocialResponse::Accept => ['I can help with the requested amount.', 'Your request is accepted; I will provide the agreed amount.'],
            AiSocialResponse::Reject => ['I cannot accept this request.', 'I must decline this request for now.'],
            AiSocialResponse::Counter => ['I can only offer {amount}.', 'I cannot meet the full request; I can offer {amount}.'],
            AiSocialResponse::Clarify => ['Please confirm the amount and terms.', 'I need clearer terms before I can respond.'],
        };
    }

    /**
     * @return list<string>
     */
    private function apologyReplyLines(AiSocialResponse $response): array
    {
        return match ($response) {
            AiSocialResponse::Accept => ['I accept your apology, but I remember what happened.', 'Your apology is acknowledged; trust will take time to rebuild.'],
            AiSocialResponse::Reject => ['An apology alone does not repair this harm.', 'I cannot accept this apology while the threat remains.'],
            AiSocialResponse::Counter => ['I need a concrete repair before we can move forward.', 'Show how you will repair this harm first.'],
            AiSocialResponse::Clarify => ['Please acknowledge the harm and explain how you will repair it.', 'I need a clearer acknowledgment before we discuss forgiveness.'],
        };
    }

    /** @return list<string> */
    private function greetingReplyLines(): array
    {
        return ['Greetings.', 'Hello.'];
    }

    /** @return list<string> */
    private function thanksReplyLines(): array
    {
        return ['You are welcome.', 'Acknowledged.'];
    }

    /** @return list<string> */
    private function tradeReplyLines(AiSocialResponse $response): array
    {
        return match ($response) {
            AiSocialResponse::Clarify => ['Please state both resources and exact amounts.'],
            AiSocialResponse::Reject => ['I cannot authorize a trade without a validated transport path.'],
            AiSocialResponse::Accept, AiSocialResponse::Counter => ['I cannot authorize this trade.'],
        };
    }

    /** @return list<string> */
    private function ceasefireReplyLines(AiSocialResponse $response): array
    {
        return match ($response) {
            AiSocialResponse::Reject => ['I cannot accept this ceasefire under the current threat.'],
            AiSocialResponse::Clarify, AiSocialResponse::Accept, AiSocialResponse::Counter => ['I cannot enforce a ceasefire through this channel.'],
        };
    }

    /** @return list<string> */
    private function warningReplyLines(AiSocialResponse $response): array
    {
        return match ($response) {
            AiSocialResponse::Reject => ['I will not accept coercive terms.'],
            AiSocialResponse::Clarify, AiSocialResponse::Accept, AiSocialResponse::Counter => ['Your warning is noted; no commitment is implied.'],
        };
    }

    /** @return list<string> */
    private function cooperationReplyLines(AiSocialResponse $response): array
    {
        return match ($response) {
            AiSocialResponse::Reject => ['I cannot cooperate under the current relationship conditions.'],
            AiSocialResponse::Clarify => ['I cannot authorize that cooperation without a supported game path.'],
            AiSocialResponse::Accept, AiSocialResponse::Counter => ['I cannot authorize that cooperation.'],
        };
    }

    /** @return list<string> */
    private function compensationReplyLines(AiSocialResponse $response): array
    {
        return match ($response) {
            AiSocialResponse::Accept => ['Your compensation is recorded and remains due.'],
            AiSocialResponse::Reject => ['I cannot accept this compensation while the threat remains.'],
            AiSocialResponse::Clarify => ['State the exact compensation and due time.'],
            AiSocialResponse::Counter => ['I need exact compensation terms before I can respond.'],
        };
    }

    /** @return list<string> */
    private function arabicReplyLines(AiSocialExchangeType $type, AiSocialResponse $response): array
    {
        return match ($type) {
            AiSocialExchangeType::HelpRequest => match ($response) {
                AiSocialResponse::Accept => ['أستطيع تقديم الكمية المطلوبة.'],
                AiSocialResponse::Reject => ['لا أستطيع قبول هذا الطلب الآن.'],
                AiSocialResponse::Counter => ['أستطيع تقديم {amount} فقط.'],
                AiSocialResponse::Clarify => ['يرجى تأكيد الكمية والشروط.'],
            },
            AiSocialExchangeType::Apology => match ($response) {
                AiSocialResponse::Accept => ['أقبل اعتذارك، لكن الثقة تحتاج إلى وقت.'],
                AiSocialResponse::Reject => ['الاعتذار وحده لا يصلح هذا الضرر.'],
                AiSocialResponse::Counter => ['أحتاج إلى تعويض واضح قبل أن نمضي قدماً.'],
                AiSocialResponse::Clarify => ['يرجى الإقرار بالضرر وتوضيح الإصلاح.'],
            },
            AiSocialExchangeType::Greeting => ['تحياتي.', 'مرحباً.'],
            AiSocialExchangeType::Thanks => ['على الرحب والسعة.'],
            AiSocialExchangeType::TradeOffer => match ($response) {
                AiSocialResponse::Clarify => ['يرجى تحديد الموارد والكميات بدقة.'],
                AiSocialResponse::Reject, AiSocialResponse::Accept, AiSocialResponse::Counter => ['لا أستطيع تفويض هذه التجارة دون مسار نقل معتمد.'],
            },
            AiSocialExchangeType::CeasefireRequest => match ($response) {
                AiSocialResponse::Reject => ['لا أستطيع قبول وقف إطلاق النار مع مستوى التهديد الحالي.'],
                AiSocialResponse::Clarify, AiSocialResponse::Accept, AiSocialResponse::Counter => ['لا أستطيع فرض وقف إطلاق النار عبر هذه القناة.'],
            },
            AiSocialExchangeType::Warning => match ($response) {
                AiSocialResponse::Reject => ['لن أقبل شروطاً قسرية.'],
                AiSocialResponse::Clarify, AiSocialResponse::Accept, AiSocialResponse::Counter => ['تمت ملاحظة التحذير؛ ولا يترتب عليه التزام.'],
            },
            AiSocialExchangeType::CooperationRequest => match ($response) {
                AiSocialResponse::Reject => ['لا أستطيع التعاون في ظل هذه العلاقة.'],
                AiSocialResponse::Clarify, AiSocialResponse::Accept, AiSocialResponse::Counter => ['لا أستطيع تفويض هذا التعاون دون مسار لعبة مدعوم.'],
            },
            AiSocialExchangeType::CompensationOffer => match ($response) {
                AiSocialResponse::Accept => ['تم تسجيل التعويض وما زال مستحقاً.'],
                AiSocialResponse::Reject => ['لا أستطيع قبول هذا التعويض مع بقاء التهديد.'],
                AiSocialResponse::Clarify => ['اذكر التعويض والموعد النهائي بدقة.'],
                AiSocialResponse::Counter => ['أحتاج إلى شروط تعويض دقيقة قبل الرد.'],
            },
        };
    }
}
