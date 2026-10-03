<?php

namespace Modules\AI\Actions;

use Modules\AI\Contracts\RunAiSession;
use Modules\AI\Domain\Scheduling\SessionDecisionService;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiWorkItem;
use Modules\AI\Support\AiClock;
use Modules\AI\Support\AiRuntimeSettings;
use Illuminate\Support\Facades\Cache;
use OGame\Models\User;
use OGame\Services\PlayerGameStateService;

/**
 * Runs one session work item.
 *
 * The conversation cycle is composed here, where the session's own work is composed,
 * rather than inside the scheduling service: the session answers what is pending and then
 * decides what to do, and the scheduling service keeps depending on nothing but its own
 * domain.
 */
class RunAiSessionAction implements RunAiSession
{
    public function __construct(
        private SessionDecisionService $sessionDecisionService,
        private ScheduleAiIntentAction $scheduleAiIntentAction,
        private AiClock $clock,
        private PlayerGameStateService $playerGameStateService,
    ) {
    }

    public function handle(AiProfile $profile, AiWorkItem $workItem): void
    {
        // Scheduled actors have no HTTP page load to advance completed host queues. Use the host
        // seam without stamping activity when the session later decides to do nothing.
        if (User::query()->whereKey($profile->player_id)->exists()) {
            $player = $this->playerGameStateService->advance($profile->player_id, null, false);

            // advance() drains the queues of the player's current planet alone; every other planet
            // is advanced only on a page visit that never happens for a scheduled actor. A colony the
            // account settles therefore fills its five queue slots and stays full forever, so it never
            // builds a shipyard, a ship or a defence. Drain each remaining planet the same way the
            // host's own page load does.
            if ($player->planets->all() !== []) {
                $currentPlanetId = $player->planets->current()->getPlanetId();

                foreach ($player->planets->all() as $planet) {
                    if ($planet->getPlanetId() === $currentPlanetId) {
                        continue;
                    }

                    $planet->update();
                }
            }
        }

        // A message is answered when the account next wakes, not when it next thinks about
        // its economy: sessions are 34 to 56 minutes apart, and that is the whole window a
        // reply has to fit inside.
        if (app(AiRuntimeSettings::class)->conversationEnabled()) {
            app(RunAiConversationCycleAction::class)->handle($profile->player_id, $this->clock->now());
        }

        // The host scheduler misses cron-minute events (HARNESS-003), so the alliance lane would never
        // run; the first session each minute runs it, which is the cadence the schedule promises (ALLY-001).
        if (Cache::add('ai:alliance-life:minute', true, 60)) {
            app(AdvanceAiAllianceLifeAction::class)->handle();
        }

        // The decision is recorded first and the intent scheduled from what it recorded, so a
        // session whose choice cannot be carried out still leaves the trace of what it wanted.
        $trace = $this->sessionDecisionService->run($profile, $workItem);
        $this->scheduleAiIntentAction->handle($profile, $workItem, $trace);
    }
}
