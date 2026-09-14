<?php

namespace Modules\AI\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Modules\AI\Actions\DeliverAiSealedReplyAction;
use Modules\AI\Actions\GenerateAiReplyAction;
use Modules\AI\Enums\AiQueueName;
use Throwable;

/**
 * Turns one sealed reply into varied wording with at most one provider request.
 *
 * A session composes a reply but must never wait for a network call, so it seals the
 * authored text and hands it here: on the language lane a slow provider delays one reply
 * instead of the player's session, and the sealed authored text stays available as the
 * fallback this job delivers whenever the provider refuses, fails or answers unusably.
 */
class GenerateAiReply implements ShouldQueue
{
    use Queueable;

    /**
     * One attempt. A retry could not differ safely from the first: the receipt is written
     * before the call and refuses a second reservation for the same reply, and an attempt
     * whose outcome was never observed is what the scheduled reconciliation closes.
     */
    public int $tries = 1;

    /**
     * The provider call budget plus reservation, settlement and delivery, kept below the
     * language lane's 60-second worker timeout so a slow generation fails before a worker
     * is killed mid-reply.
     */
    public int $timeout;

    public function __construct(public int $replyId)
    {
        $this->onQueue(AiQueueName::AiLanguage->value);
        $this->timeout = max(10, (int) config('ai.language.timeout_seconds', 20)) + 30;
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['ai', 'ai:language', 'ai:reply:' . $this->replyId];
    }

    public function handle(GenerateAiReplyAction $generateAiReply): void
    {
        $generateAiReply->handle($this->replyId);
    }

    /**
     * A worker that died before writing the receipt leaves no attempt for the reconciliation
     * to close, so the authored reply is delivered here instead of the message quietly never
     * being answered. Delivery is idempotent, so a failure that happened after a successful
     * send returns the recorded message rather than sending a second one.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('AI language reply failed; delivering the authored reply', [
            'reply_id' => $this->replyId,
            'error' => $exception->getMessage(),
        ]);

        app(DeliverAiSealedReplyAction::class)->handle($this->replyId);
    }
}
