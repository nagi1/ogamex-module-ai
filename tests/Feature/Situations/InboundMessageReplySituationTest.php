<?php

use Modules\AI\Tests\Support\Situation;
use OGame\Models\ChatMessage;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// SOC-001's social lane is observation-triggered: the account never speaks unless something
// arrives. So the two ends that have to hold are that an arriving message is answered and that
// nothing arriving keeps the account quiet -- the boundary the cohort cannot be trusted to show,
// because on a cohort nobody writes.

test('a direct message is answered on the next login', function (): void {
    Situation::of($this)
        ->directMessage('hello there')
        ->minutesLater(40)
        ->session()
        ->expectReply();
});

test('no inbound message leaves the chat lane silent', function (): void {
    Situation::of($this)->conversations()->session();

    expect(ChatMessage::query()->where('sender_id', $this->currentUserId)->count())
        ->toBe(0, 'expected no chat from the account with nothing written to it');
});
