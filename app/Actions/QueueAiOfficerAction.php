<?php

namespace Modules\AI\Actions;

use Exception;
use Modules\AI\Enums\AiQueueActionReason;
use Modules\AI\Support\AiActionResult;
use OGame\Models\User;
use OGame\Services\DarkMatterService;
use OGame\Services\OfficerService;

/**
 * The host's ordinary officer purchase for one account. The host validates the officer and the duration and debits and
 * activates in one transaction, so the only check here is that the account can pay.
 */
class QueueAiOfficerAction
{
    public function __construct(
        private OfficerService $officerService,
        private DarkMatterService $darkMatterService,
    ) {
    }

    public function handle(int $playerId, string $officerKey, int $days): AiActionResult
    {
        $user = User::query()->find($playerId);
        if ($user === null || !$this->darkMatterService->canAfford($user, $this->officerService->getCost($officerKey, $days))) {
            return AiActionResult::rejected(AiQueueActionReason::NothingQueueable);
        }

        try {
            $this->officerService->purchase($user, $officerKey, $days);
        } catch (Exception $exception) {
            return AiActionResult::rejected($exception->getMessage());
        }

        return AiActionResult::succeeded(AiQueueActionReason::OfficerPurchased);
    }
}
