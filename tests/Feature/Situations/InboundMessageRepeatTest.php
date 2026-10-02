<?php

use Modules\AI\Tests\Support\Situation;
use OGame\Models\ChatMessage;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

/**
 * SOC-001: the reply protocol is bounded per conversation, not per counterparty for the rest of
 * the account's life. Counting every reply ever composed means an account that has answered a
 * neighbour twice never records another exchange with them, which is the dormancy the cohort
 * read shows as "machinery alive, behaviour dead".
 */
function sentReplies(int $playerId): int
{
    return ChatMessage::query()->where('sender_id', $playerId)->count();
}

test('a neighbour who writes again inside the conversation window is still capped at two turns', function (): void {
    $situation = Situation::of($this)
        ->directMessage('hey, are you active? want to trade?')->session()
        ->directMessage('hey, still there? want to trade?')->minutesLater(45)->session()
        ->directMessage('hey, we share a system. no attacks between us')->minutesLater(45)->session();

    expect(sentReplies($this->currentUserId))->toBe(2, 'expected two turns inside one conversation, but ' . $situation->account());
});

test('a neighbour who writes after the conversation window closed is answered again', function (): void {
    $situation = Situation::of($this)
        ->directMessage('hey, are you active? want to trade?')->session()
        ->directMessage('hey, we share a system. no attacks between us')->minutesLater(400)->session();

    expect(sentReplies($this->currentUserId))->toBe(2, 'expected the second message answered once the first conversation had ended, but ' . $situation->account());
});
