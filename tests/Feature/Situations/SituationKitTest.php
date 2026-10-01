<?php

use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Tests\Support\Situation;
use OGame\Models\AllianceApplication;
use OGame\Models\ChatMessage;
use OGame\Models\DebrisField;
use OGame\Models\EspionageReport;
use OGame\Models\Message;
use OGame\Models\Planet;
use Tests\IsolatedAccountTestCase;

require_once __DIR__ . '/../../Support/Situation.php';

uses(IsolatedAccountTestCase::class);

// The kit's own proof: planted state in, the account's real session out, in about a second each. These
// double as the examples a writer copies, so every one reads as the story it proves.

test('a funded account with a free planet queues a building', function (): void {
    Situation::of($this)
        ->resources(1_000_000, 1_000_000, 1_000_000)
        ->session()
        ->expectWork(AiWorkKind::BuildFirstBuilding);
});

test('an account with no debris and no ship creates no recycle', function (): void {
    DebrisField::query()->delete();

    Situation::of($this)->session()->expectNoWork(AiWorkKind::Recycle);
});

test('a funded session leaves its building in the host queue', function (): void {
    $situation = Situation::of($this)->resources(1_000_000, 1_000_000, 1_000_000)->session();

    expect($situation->queued())->not->toBeEmpty()
        ->and($situation->candidates())->not->toBeEmpty();
});

test('a colony is one more planet the account owns', function (): void {
    $before = Planet::query()->where('user_id', $this->currentUserId)->count();

    Situation::of($this)->colony();

    expect(Planet::query()->where('user_id', $this->currentUserId)->count())->toBe($before + 1);
});

test('a spy report on the neighbour lands in the account inbox with its stock', function (): void {
    Situation::of($this)->inactiveNeighbour(metal: 300_000)->neighbourUnits('rocket_launcher', 5)->spyReport();

    $reportId = Message::query()->where('user_id', $this->currentUserId)->whereNotNull('espionage_report_id')->value('espionage_report_id');
    $report = EspionageReport::query()->findOrFail($reportId);

    expect($report->resources['metal'])->toBeGreaterThanOrEqual(300_000)
        ->and($report->defense['rocket_launcher'] ?? 0)->toBe(5);
});

test('an alliance application waits on the account the leader', function (): void {
    Situation::of($this)->allianceApplication(minutesAgo: 30);

    expect(AllianceApplication::query()->where('status', AllianceApplication::STATUS_PENDING)->count())->toBe(1);
});

test('a direct message reaches the account through the host chat', function (): void {
    Situation::of($this)->directMessage('hello there');

    expect(ChatMessage::query()->where('recipient_id', $this->currentUserId)->value('message'))->toBe('hello there');
});
