<?php

namespace Modules\AI\Actions;

use Exception;
use OGame\Factories\PlanetServiceFactory;
use OGame\GameMissions\TransportMission;
use OGame\Models\Enums\PlanetType;
use OGame\Models\Planet;
use OGame\Models\Resources;
use OGame\Services\FleetMissionService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerGameStateService;

/**
 * A vassal member's daily tribute (rl/ogsim src/season.rs, "Tribute: each vassal"): a share of its home planet's stock
 * flown to the overlord's home planet as a real Transport, the cargo hulls it owns carrying it. No hulls for the whole
 * load and its fuel, no tribute that day; the host's own dispatch checks and debits everything.
 */
class PayAiTributeAction
{
    /** 100%: tribute is delivered, not parked in space. */
    private const SPEED = 10.0;

    public function handle(int $payerId, int $lordId, float $share): bool
    {
        [$homeId, $lordHomeId] = [$this->home($payerId), $this->home($lordId)];
        if ($homeId === null || $lordHomeId === null || $payerId === $lordId) {
            return false;
        }

        try {
            $player = app(PlayerGameStateService::class)->advance($payerId, $homeId);
            if ($player->isBanned() || $player->isInVacationMode()) {
                return false;
            }

            $source = $player->planets->current();
            $target = app(PlanetServiceFactory::class)->make($lordHomeId, true);
            $paid = new Resources(floor($source->metal()->get() * $share), floor($source->crystal()->get() * $share), floor($source->deuterium()->get() * $share));
            $transfer = app(QueueAiTransferAction::class);
            $fleet = $paid->sum() > 0 ? $transfer->transportFleet($player, $source, $paid) : null;
            if ($fleet === null || !$target instanceof PlanetService) {
                return false;
            }

            $missions = app()->makeWith(FleetMissionService::class, ['player' => $player]);
            // The flight's fuel rides in the same holds: the fleet is sized for the tribute and its fuel together.
            $fuel = (int) ceil($missions->calculateConsumption($source, $fleet, $target->getPlanetCoordinates(), 0, self::SPEED));
            $fleet = $transfer->transportFleet($player, $source, new Resources($paid->metal->get() + $fuel, $paid->crystal->get(), $paid->deuterium->get()));
            if ($fleet === null || $fuel + $paid->deuterium->get() > floor($source->deuterium()->get())) {
                return false;
            }

            $missions->createNewFromPlanet($source, $target->getPlanetCoordinates(), $target->getPlanetType(), TransportMission::getTypeId(), $fleet, $paid, self::SPEED);

            return true;
        } catch (Exception) {
            return false;
        }
    }

    /** The account's homeworld: its first planet. */
    private function home(int $userId): int|null
    {
        $id = Planet::query()->where('user_id', $userId)->where('planet_type', PlanetType::Planet->value)->orderBy('id')->value('id');

        return $id === null ? null : (int) $id;
    }
}
