<?php

namespace Modules\AI\Actions;

use Exception;
use Modules\AI\Contracts\QueueAiTrade;
use Modules\AI\Domain\Market\MarketRatioBand;
use Modules\AI\Enums\AiQueueActionReason;
use Modules\AI\Support\AiActionResult;
use OGame\Factories\PlanetServiceFactory;
use OGame\Models\Planet;
use OGame\Services\MerchantService;
use OGame\Services\PlayerGameStateService;

/**
 * Module-owned adapter over the host's resource merchant: call the merchant for the resource being
 * sold, keep the offer in the cache the host's own page reads, then execute the trade. The dark matter
 * debit, the rates and the storage capping are the host's.
 */
class QueueAiTradeAction implements QueueAiTrade
{
    public function __construct(
        private PlayerGameStateService $playerGameStateService,
        private PlanetServiceFactory $planetServiceFactory,
    ) {
    }

    public function handle(int $playerId, int $planetId, string $giveResource, string $receiveResource, int $giveAmount): AiActionResult
    {
        if (!Planet::query()->whereKey($planetId)->where('user_id', $playerId)->exists()) {
            return AiActionResult::rejected(AiQueueActionReason::PlanetNotOwned);
        }

        try {
            $player = $this->playerGameStateService->advance($playerId, $planetId);
            if ($player->isBanned()) {
                return AiActionResult::rejected(AiQueueActionReason::PlayerBanned);
            }
            if ($player->isInVacationMode()) {
                return AiActionResult::rejected(AiQueueActionReason::VacationMode);
            }

            $planet = $this->planetServiceFactory->makeForPlayer($player, $planetId, false);

            $called = MerchantService::callMerchant($player, $giveResource);
            if (!$called['success']) {
                return AiActionResult::rejected($called['message']);
            }
            cache()->forever('active_merchant_' . $playerId, [
                'type' => $giveResource,
                'trade_rates' => $called['tradeRates'] ?? [],
                'called_at' => now()->getTimestamp(),
            ]);

            $rate = (float) ($called['tradeRates']['receive'][$receiveResource]['rate'] ?? 0.0);
            // An offer outside the documented band around the base rate is not taken (WIK-226); the
            // merchant stays called for the next pass.
            if (!app(MarketRatioBand::class)->rateInBand($rate, MerchantService::getBaseRate($receiveResource))) {
                return AiActionResult::rejected(AiQueueActionReason::NothingQueueable);
            }
            $desired = (int) floor($giveAmount * $rate / MerchantService::getBaseRate($giveResource));
            if ($desired <= 0) {
                return AiActionResult::rejected(AiQueueActionReason::NothingQueueable);
            }

            $result = MerchantService::executeTrade($player, $planet, $giveResource, [$receiveResource => $desired], $giveAmount);

            return $result['success']
                ? AiActionResult::queued(0)
                : AiActionResult::rejected($result['message']);
        } catch (Exception $exception) {
            return AiActionResult::rejected($exception->getMessage());
        }
    }
}
