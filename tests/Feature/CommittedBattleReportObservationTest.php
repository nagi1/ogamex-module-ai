<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Modules\AI\Enums\AiAffectEmotion;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Models\AiEmotionalEpisode;
use Modules\AI\Models\AiObservation;
use Modules\AI\Models\AiProfile;
use OGame\Models\BattleReport;
use OGame\Models\User;
use Tests\TestCase;

/**
 * The observer is the only thing that turns a committed battle into cognition, so it is
 * exercised with the module actually booted instead of by calling the reducer by hand.
 * A test transaction never reaches the deferred after-commit callback, which is why this
 * lives apart from the reducer suite.
 */
class CommittedBattleReportObservationTest extends TestCase
{
    use DatabaseTransactions;

    /** @var list<int> */
    private array $createdPlayerIds = [];

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
        AiEmotionalEpisode::query()->whereIn('player_id', $this->createdPlayerIds)->delete();
        AiObservation::query()->whereIn('player_id', $this->createdPlayerIds)->delete();
        AiProfile::query()->whereIn('player_id', $this->createdPlayerIds)->delete();
        User::query()->whereKey($this->createdPlayerIds)->delete();

        if (is_file($this->statusesFile)) {
            unlink($this->statusesFile);
        }

        putenv('MODULES_STATUSES_FILE');

        parent::tearDown();
    }

    public function test_a_committed_battle_report_is_observed_and_appraised_without_a_caller(): void
    {
        $defender = $this->createPlayer();
        $attacker = $this->createPlayer();

        AiProfile::create([
            'player_id' => $defender->id,
            'archetype' => AiArchetype::Fleeter,
            'skill_band' => AiSkillBand::Standard,
            'random_seed' => 1,
            'enabled' => true,
        ]);

        $report = BattleReport::unguarded(fn (): BattleReport => BattleReport::create([
            'planet_galaxy' => 1,
            'planet_system' => 1,
            'planet_position' => 1,
            'planet_user_id' => $defender->id,
            'attacker' => ['player_id' => $attacker->id, 'resource_loss' => 100.0],
            'defender' => ['player_id' => $defender->id, 'resource_loss' => 400.0],
        ]));

        $observation = AiObservation::query()->where('player_id', $defender->id)->sole();

        expect($observation->source_id)->toBe($report->id)
            ->and($observation->subject_player_id)->toBe($attacker->id);

        $episode = AiEmotionalEpisode::query()->where('player_id', $defender->id)->sole();

        expect($episode->emotion)->toBe(AiAffectEmotion::Anger)
            ->and((float) $episode->intensity)->toBe(0.8);
    }

    private function createPlayer(): User
    {
        $player = User::withoutEvents(fn (): User => User::factory()->create([
            'username' => 'battle_observer_' . Str::random(16),
        ]));
        $this->createdPlayerIds[] = $player->id;

        return $player;
    }
}
