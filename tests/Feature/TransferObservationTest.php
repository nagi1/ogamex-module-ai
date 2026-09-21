<?php

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\AI\Actions\ClassifyInboundSocialExchangeAction;
use Modules\AI\Actions\EvaluateAiSocialExchangeAction;
use Modules\AI\Actions\RecordAiSocialExchangeAction;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiCommitmentDirection;
use Modules\AI\Enums\AiCommitmentState;
use Modules\AI\Enums\AiObservationKind;
use Modules\AI\Enums\AiObservationSource;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Enums\AiSocialExchangeType;
use Modules\AI\Enums\AiSocialResource;
use Modules\AI\Enums\AiSocialTerm;
use Modules\AI\Models\AiCommitment;
use Modules\AI\Models\AiObservation;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiRelationship;
use OGame\Models\Message;
use OGame\Models\Planet;
use OGame\Models\User;
use Tests\TestCase;

class TransferObservationTestCase extends TestCase
{
    use DatabaseTransactions;

    /** @var list<int> */
    private array $createdPlayerIds = [];

    /** @var list<int> */
    private array $createdPlanetIds = [];

    private string $statusesFile = '';

    public function createApplication(): Application
    {
        $trackedFile = dirname(__DIR__, 4) . '/modules_statuses.json';
        $statuses = json_decode((string) file_get_contents($trackedFile), true);
        $statuses['AI'] = true;
        $this->statusesFile = sys_get_temp_dir() . '/modules_statuses_' . uniqid('', true) . '.json';
        file_put_contents($this->statusesFile, json_encode($statuses, JSON_PRETTY_PRINT));
        putenv('MODULES_STATUSES_FILE=' . $this->statusesFile);

        return parent::createApplication();
    }

    protected function tearDown(): void
    {
        Message::query()->whereIn('user_id', $this->createdPlayerIds)->delete();
        AiCommitment::query()->whereIn('player_id', $this->createdPlayerIds)->delete();
        AiRelationship::query()->whereIn('player_id', $this->createdPlayerIds)->delete();
        AiObservation::query()->whereIn('player_id', $this->createdPlayerIds)->delete();
        AiProfile::query()->whereIn('player_id', $this->createdPlayerIds)->delete();
        Planet::query()->whereKey($this->createdPlanetIds)->delete();
        User::query()->whereKey($this->createdPlayerIds)->delete();

        if (is_file($this->statusesFile)) {
            unlink($this->statusesFile);
        }

        putenv('MODULES_STATUSES_FILE');

        parent::tearDown();
    }

    protected function createPlayer(): User
    {
        $player = User::withoutEvents(fn (): User => User::factory()->create([
            'username' => 'ai_transfer_' . Str::random(16),
        ]));
        $this->createdPlayerIds[] = $player->id;

        return $player;
    }

    protected function createProfile(User $player): AiProfile
    {
        return AiProfile::create([
            'player_id' => $player->id,
            'archetype' => AiArchetype::Miner,
            'skill_band' => AiSkillBand::Standard,
            'random_seed' => 42,
            'enabled' => true,
        ]);
    }

    protected function createPlanet(User $owner): Planet
    {
        $planet = Planet::factory()->create([
            'user_id' => $owner->id,
            'galaxy' => 5,
            'system' => 10,
            'planet' => 15,
            'time_last_update' => now()->subHour()->getTimestamp(),
        ]);
        $this->createdPlanetIds[] = $planet->id;

        return $planet;
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function createTransportMessage(User $recipient, array $params): Message
    {
        $message = null;

        DB::transaction(function () use ($recipient, $params, &$message): void {
            $message = new Message();
            $message->user_id = $recipient->id;
            $message->key = 'transport_received';
            $message->params = $params;
            $message->save();
        });

        return $message;
    }

    protected function acceptedCompensation(User $recipient, User $sender, int $amount): AiCommitment
    {
        return AiCommitment::create([
            'player_id' => $recipient->id,
            'counterparty_player_id' => $sender->id,
            'source_observation_id' => 9001,
            'terms' => [
                AiSocialTerm::Resource->value => AiSocialResource::Crystal->value,
                AiSocialTerm::Amount->value => $amount,
            ],
            'state' => AiCommitmentState::Accepted,
            'direction' => AiCommitmentDirection::ExpectedFromCounterparty,
            'due_at' => now()->addDay(),
            'revision' => 1,
        ]);
    }
}

uses(TransferObservationTestCase::class);

test('a committed inbound transport becomes a transfer observation for only its AI recipient', function (): void {
    $sender = $this->createPlayer();
    $recipient = $this->createPlayer();
    $this->createProfile($recipient);
    $senderPlanet = $this->createPlanet($sender);

    $this->createTransportMessage($recipient, [
        'from' => "[planet]{$senderPlanet->id}[/planet]",
        'to' => '[planet]1[/planet]',
        'metal' => '0',
        'crystal' => '200',
        'deuterium' => '0',
    ]);

    $observation = AiObservation::query()->sole();

    expect($observation->player_id)->toBe($recipient->id)
        ->and($observation->subject_player_id)->toBe($sender->id)
        ->and($observation->source_type)->toBe(AiObservationSource::FleetMessage)
        ->and($observation->kind)->toBe(AiObservationKind::TransferReceived)
        ->and($observation->source_id)->toBeInt();
});

test('a non-transport message is never observed', function (): void {
    $recipient = $this->createPlayer();
    $this->createProfile($recipient);

    $message = null;
    DB::transaction(function () use ($recipient, &$message): void {
        $message = new Message();
        $message->user_id = $recipient->id;
        $message->key = 'transport_arrived';
        $message->params = [];
        $message->save();
    });

    expect(AiObservation::query()->where('player_id', $recipient->id)->exists())->toBeFalse();
});

test('a rolled back transport message never becomes an observation', function (): void {
    $sender = $this->createPlayer();
    $recipient = $this->createPlayer();
    $this->createProfile($recipient);
    $senderPlanet = $this->createPlanet($sender);

    try {
        DB::transaction(function () use ($senderPlanet, $recipient): void {
            $message = new Message();
            $message->user_id = $recipient->id;
            $message->key = 'transport_received';
            $message->params = ['from' => "[planet]{$senderPlanet->id}[/planet]", 'metal' => '0', 'crystal' => '0', 'deuterium' => '0'];
            $message->save();

            throw new RuntimeException('Force the transaction to roll back.');
        });
    } catch (RuntimeException) {
    }

    expect(AiObservation::query()->where('player_id', $recipient->id)->exists())->toBeFalse();
});

test('a self-delivery is not an observation', function (): void {
    $player = $this->createPlayer();
    $this->createProfile($player);
    $ownPlanet = $this->createPlanet($player);

    $this->createTransportMessage($player, [
        'from' => "[planet]{$ownPlanet->id}[/planet]",
        'to' => '[planet]2[/planet]',
        'metal' => '0',
        'crystal' => '200',
        'deuterium' => '0',
    ]);

    expect(AiObservation::query()->where('player_id', $player->id)->exists())->toBeFalse();
});

test('a covering delivery fulfils the accepted compensation promise and repairs trust', function (): void {
    $sender = $this->createPlayer();
    $recipient = $this->createPlayer();
    $this->createProfile($recipient);
    $senderPlanet = $this->createPlanet($sender);
    $commitment = $this->acceptedCompensation($recipient, $sender, 200);

    $this->createTransportMessage($recipient, [
        'from' => "[planet]{$senderPlanet->id}[/planet]",
        'to' => '[planet]1[/planet]',
        'metal' => '0',
        'crystal' => '250',
        'deuterium' => '0',
    ]);

    $fulfilled = $commitment->fresh();

    expect($fulfilled->state)->toBe(AiCommitmentState::Fulfilled)
        ->and($fulfilled->fulfillment_observation_id)->not->toBeNull();

    $relationship = AiRelationship::query()
        ->where('player_id', $recipient->id)
        ->where('other_player_id', $sender->id)
        ->sole();

    expect((float) $relationship->trust)->toBe(0.2)
        ->and((float) $relationship->threat)->toBe(0.0)
        ->and((float) $relationship->affinity)->toBe(0.1);
});

test('a partial delivery does not settle the promise', function (): void {
    $sender = $this->createPlayer();
    $recipient = $this->createPlayer();
    $this->createProfile($recipient);
    $senderPlanet = $this->createPlanet($sender);
    $commitment = $this->acceptedCompensation($recipient, $sender, 200);

    $this->createTransportMessage($recipient, [
        'from' => "[planet]{$senderPlanet->id}[/planet]",
        'to' => '[planet]1[/planet]',
        'metal' => '0',
        'crystal' => '100',
        'deuterium' => '0',
    ]);

    expect($commitment->fresh()->state)->toBe(AiCommitmentState::Accepted)
        ->and(AiRelationship::query()->where('player_id', $recipient->id)->where('other_player_id', $sender->id)->exists())->toBeFalse();
});

test('an inbound compensation offer is classified with resource and amount terms', function (): void {
    $classification = app(ClassifyInboundSocialExchangeAction::class)->handle('sorry about the raid, I will send 200 crystal to make up for it');

    expect($classification)->not->toBeNull()
        ->and($classification->type)->toBe(AiSocialExchangeType::CompensationOffer)
        ->and($classification->terms)->toBe([
            AiSocialTerm::AcknowledgesHarm->value => true,
            AiSocialTerm::Resource->value => AiSocialResource::Crystal->value,
            AiSocialTerm::Amount->value => 200.0,
        ]);
});

test('a compensation offer without a stated deadline still records an accepted commitment', function (): void {
    $recipient = $this->createPlayer();
    $sender = $this->createPlayer();
    $this->createProfile($recipient);
    $now = CarbonImmutable::parse('2026-09-20 10:00:00 UTC');

    $exchange = app(RecordAiSocialExchangeAction::class)->handle(
        $recipient->id,
        $sender->id,
        9002,
        AiSocialExchangeType::CompensationOffer,
        [
            AiSocialTerm::AcknowledgesHarm->value => true,
            AiSocialTerm::Resource->value => AiSocialResource::Crystal->value,
            AiSocialTerm::Amount->value => 200,
        ],
    );

    $evaluated = app(EvaluateAiSocialExchangeAction::class)->handle($exchange->id, 0, $now);
    $commitment = AiCommitment::query()->findOrFail($evaluated->commitment_id);

    expect($commitment->state)->toBe(AiCommitmentState::Accepted)
        ->and($commitment->direction)->toBe(AiCommitmentDirection::ExpectedFromCounterparty)
        ->and($commitment->due_at?->equalTo($now->addHours(48)))->toBeTrue();
});
