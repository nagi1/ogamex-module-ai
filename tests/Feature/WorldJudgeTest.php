<?php

use Laravel\Ai\Classification;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\Data\ScoreAnswer;
use Modules\AI\Ai\Agents\OgameWorldJudgeAgent;
use Modules\AI\Enums\AiAllianceStance;
use Modules\AI\Models\AiAllianceRelation;
use OGame\Models\ChatMessage;
use OGame\Models\User;
use OGame\Services\AllianceService;
use Tests\IsolatedAccountTestCase;

uses(IsolatedAccountTestCase::class);

// The late-world loop's grader (llm-classifier-everywhere.md, step 6): the record the judge reads carries the wars,
// the battles by side and the talk, and the judgement comes back as grades and findings.

test('the judge reads the wars and the talk and returns grades with findings', function (): void {
    config(['ai.language.provider' => 'openai', 'ai.language.model' => 'gpt-5-mini']);
    $ours = app(AllianceService::class)->createAlliance($this->currentUserId, 'OURS', 'Ours');
    $other = User::factory()->create();
    $theirs = app(AllianceService::class)->createAlliance($other->id, 'THEM', 'Them');
    AiAllianceRelation::query()->create(['alliance_id' => $ours->id, 'other_alliance_id' => $theirs->id, 'stance' => AiAllianceStance::War, 'cause' => 'grievance', 'started_at' => now()]);
    ChatMessage::create(['sender_id' => $this->currentUserId, 'recipient_id' => $other->id, 'message' => 'your members have hit ours once too often']);
    Classification::fake([['real' => new ScoreAnswer(2.0, [0.0, 0.1, 0.8, 0.1], ['no', 'little', 'mostly', 'fully'])]]);
    OgameWorldJudgeAgent::fake([['great_war' => 2, 'politics' => 1, 'human_like' => 2, 'findings' => [
        ['aspect' => 'war', 'observation' => 'OURS declared on THEM', 'better_play' => 'open with an ACS on their moons'],
    ]]]);

    $this->artisan('ai:judge-world')
        ->expectsOutputToContain('great war 2/3, politics 1/3, human-like 2/3, talk (classifier) 2/3')
        ->expectsOutputToContain('open with an ACS on their moons')
        ->assertSuccessful();

    OgameWorldJudgeAgent::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->contains('[OURS] -> [THEM] war (grievance)')
        && $prompt->contains('your members have hit ours once too often'));
});
