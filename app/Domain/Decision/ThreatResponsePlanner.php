<?php

namespace Modules\AI\Domain\Decision;

use Modules\AI\Enums\AiThreatResponse;
use OGame\Factories\PlayerServiceFactory;
use OGame\GameMissions\EspionageMission;
use OGame\Models\Resources;
use OGame\Models\User;
use OGame\Services\FleetMissionService;
use OGame\Services\ObjectService;
use OGame\Services\PlanetService;
use OGame\Services\PlayerService;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * What an inbound hostile fleet asks the account to do, per threatened body.
 *
 * `QueueableUnitPlanner::underAttack` used to answer this with one reactive turret on the first planet
 * the fleet was headed at. A player answers an attack with one order for each thing he stands to lose:
 * the fleet that is worth the trip leaves (`EvacuateFleet`), a fleet the wall already covers stays home
 * as bait (`AttemptNinja`), the stock that would be carried off leaves on the hulls he keeps
 * (`EvacuateResources`), the wall that has to take the hit is bought now (`ReinforceDefense`), or the
 * inbound is one there is nothing worth doing about (`DoNothing`). Several of them are true for the
 * same attack, and one login places all of them.
 *
 * The class names the response and nothing else: every one of them is fulfilled by the planner that
 * already owns that order — the fleet-save planner, the ferry planner, the defence-composition planner
 * — so no unit, coordinate or amount is decided here, and a mod-added hull changes both halves at once.
 * Whether an attack is happening and which bodies it is aimed at stay the host's own answers
 * (`currentPlayerUnderAttack` and the active fleet missions), so neither this class nor the behaviour
 * file keeps a mission-type list.
 *
 * The one number that decides between these responses is policy and lives in
 * `resources/behavior/threat-response.yaml`: how much wall has to stand behind a fleet the account
 * keeps home before the hold is worth calling an ambush.
 */
class ThreatResponsePlanner
{
    private const POLICY_FILE = 'resources/behavior/threat-response.yaml';

    /** Metal-equivalent weights, the same ones the rest of the module prices objects with. */
    private const CRYSTAL_WEIGHT = 1.5;

    private const DEUTERIUM_WEIGHT = 2.0;

    private ?float $ratio = null;

    public function __construct(
        private PlayerServiceFactory $playerServiceFactory,
        private QueueableFleetSavePlanner $fleetSave,
        private QueueableTransferPlanner $transfer,
        private DefenseNeedEvaluator $defenseNeed,
        private ?string $policyFile = null,
    ) {
    }

    public function plan(int $playerId, ?PlayerService $player = null): ThreatResponsePlan
    {
        // An account the host no longer has has nothing an inbound could be answered with; asking the
        // host for its planets would build a player for an id that does not exist, which the host refuses.
        if (! User::query()->whereKey($playerId)->exists()) {
            return app()->makeWith(ThreatResponsePlan::class, ['underAttack' => false]);
        }

        $player ??= $this->playerServiceFactory->make($playerId, true);
        $missions = app()->makeWith(FleetMissionService::class, ['player' => $player]);

        $threatened = $this->threatenedPlanetIds($player, $missions);
        if ($threatened === []) {
            return $this->unreadInbound($player, $missions);
        }

        // The fleet-save planner owns whether a fleet is worth moving at all (FS-001), so it is asked
        // rather than restated: the save it returns names the body the fleet would leave from.
        $save = $this->fleetSave->plan($playerId, $player);
        $responses = [];
        $evacuations = [];

        foreach ($player->planets->all() as $planet) {
            if (isset($threatened[$planet->getPlanetId()])) {
                $responses[$planet->getPlanetId()] = $this->responsesFor($player, $planet, $save, array_keys($threatened), $evacuations);
            }
        }

        return app()->makeWith(ThreatResponsePlan::class, [
            'underAttack' => true,
            'responses' => $responses,
            'evacuations' => $evacuations,
        ]);
    }

    /**
     * The answer when the host reports no inbound that can be located: no attack, or no destination the
     * account could name. The host's own under-attack flag is the only reading left, and a hostile the
     * account cannot see the target of is answered by the wall on every body -- the same reactive wall
     * the account bought before a destination could be read at all, so an inbound to a body the host
     * does not list leaves the account bare no longer.
     */
    private function unreadInbound(PlayerService $player, FleetMissionService $missions): ThreatResponsePlan
    {
        if (! $missions->currentPlayerUnderAttack()) {
            return app()->makeWith(ThreatResponsePlan::class, ['underAttack' => false]);
        }

        $wall = [];
        foreach ($player->planets->all() as $planet) {
            $wall[$planet->getPlanetId()] = [AiThreatResponse::ReinforceDefense];
        }

        return app()->makeWith(ThreatResponsePlan::class, ['underAttack' => true, 'responses' => $wall]);
    }

    /**
     * The wall a threatened body's reinforcement is sized to: what its own exposure asks for, or what a
     * fleet the account keeps home wants behind it to be bait, whichever is more. Null when the wall
     * already covers both, which is the answer the unit planner reads as "nothing to reinforce".
     *
     * The need is a value, so how many units that is and which of them stays the composition planner's
     * question; a bait need names the fleet it is covering as the protected value, so a trace can show
     * why the wall grew rather than only that it did.
     */
    public function reinforcementNeed(PlayerService $player, PlanetService $planet, ?DefenseNeed $exposure): ?DefenseNeed
    {
        $fleet = $this->movableFleetValue($player, $planet);
        if ($fleet <= 0.0) {
            return $exposure;
        }

        $bait = $this->baitRatio() * $fleet;
        if (($exposure?->defenceValue ?? 0.0) >= $bait || $this->wallValue($planet) >= $bait) {
            return $exposure;
        }

        return app()->makeWith(DefenseNeed::class, [
            'defenceValue' => $bait,
            'reason' => 'defense:need:bait',
            'protectedValue' => $fleet,
            'currentDefenseValue' => $this->wallValue($planet),
            'threatBand' => 'inbound',
            'intent' => 'reinforce',
        ]);
    }

    /**
     * What this body answers the inbound with, in the order the account acts on it: the fleet first, it
     * is what the trip is for, then the stock, then the wall.
     *
     * The two fleet answers exclude each other, and so does the stock: the save and the ferry both need
     * the body's hulls, so a body whose fleet is leaving has nothing left at home to carry its pile with
     * and the pile stays where it is.
     *
     * @param array<int, int> $threatenedPlanetIds every body this same inbound is aimed at
     * @param array<int, QueueableTransfer> $evacuations filled with the ferry a body that carries its stock off flies
     * @return list<AiThreatResponse>
     */
    private function responsesFor(PlayerService $player, PlanetService $planet, ?QueueableFleetSave $save, array $threatenedPlanetIds, array &$evacuations): array
    {
        $fleet = $this->movableFleetValue($player, $planet);
        $answer = $this->fleetResponse($planet, $save, $fleet);
        $leaving = $answer === AiThreatResponse::EvacuateFleet;
        $responses = $answer === null ? [] : [$answer];

        // The save and the ferry both need this body's hulls, so a body whose fleet is leaving has
        // nothing left at home to carry its pile with and the pile stays where it is. Which sibling
        // receives it and what fits in the holds stays the ferry planner's and the adapter's question.
        if (! $leaving) {
            $ferry = $this->transfer->evacuationPlan($player->getId(), $planet->getPlanetId(), $threatenedPlanetIds);
            if ($ferry instanceof QueueableTransfer) {
                $responses[] = AiThreatResponse::EvacuateResources;
                $evacuations[$planet->getPlanetId()] = $ferry;
            }
        }

        // The wall: what this body stands to lose, or what a fleet kept home behind it needs.
        if ($this->defenseNeed->evaluate($player, $planet) !== null || $this->baitStillOwesWall($planet, $fleet)) {
            $responses[] = AiThreatResponse::ReinforceDefense;
        }

        return $responses === [] ? [AiThreatResponse::DoNothing] : $responses;
    }

    /**
     * The fleet's own answer to the inbound: it leaves, it stays home as bait, or there is nothing this
     * body can do for it. The two answers exclude each other, because a fleet that leaves cannot also be
     * held.
     */
    private function fleetResponse(PlanetService $planet, ?QueueableFleetSave $save, float $fleet): ?AiThreatResponse
    {
        if ($save !== null && $save->originPlanetId === $planet->getPlanetId()) {
            return AiThreatResponse::EvacuateFleet;
        }

        if ($fleet <= 0.0 || $this->wallValue($planet) < $this->baitRatio() * $fleet) {
            return null;
        }

        return AiThreatResponse::AttemptNinja;
    }

    /**
     * Whether a wall still has to be bought for a fleet the account is keeping home: the hold is not an
     * ambush until the wall stands at the file's multiple of what that fleet is worth.
     */
    private function baitStillOwesWall(PlanetService $planet, float $fleet): bool
    {
        return $fleet > 0.0 && $this->wallValue($planet) < $this->baitRatio() * $fleet;
    }

    /**
     * The own bodies a foreign, non-espionage fleet is aimed at. A probe alone is not an attack (FS-012),
     * so it produces no response; which mission types count stays the host's own answer.
     *
     * @return array<int, true>
     */
    private function threatenedPlanetIds(PlayerService $player, FleetMissionService $missions): array
    {
        $threatened = [];

        foreach ($missions->getActiveFleetMissionsForCurrentPlayer() as $mission) {
            if ($mission->user_id !== $player->getId() && $mission->mission_type !== EspionageMission::getTypeId()) {
                $threatened[(int) $mission->planet_id_to] = true;
            }
        }

        return $threatened;
    }

    /** The metal-equivalent value of the hulls this body could fly away with, satellites excluded. */
    private function movableFleetValue(PlayerService $player, PlanetService $planet): float
    {
        $value = 0.0;

        foreach (MovableFleet::of($player, $planet->getShipUnits())->toArray() as $machineName => $amount) {
            $value += $this->metalEquivalent(ObjectService::getObjectRawPrice($machineName)) * $amount;
        }

        return $value;
    }

    /** The metal-equivalent value of the wall this body stands, built defence only. */
    private function wallValue(PlanetService $planet): float
    {
        $value = 0.0;

        foreach ($planet->getDefenseUnits()->toArray() as $machineName => $amount) {
            $value += $this->metalEquivalent(ObjectService::getObjectRawPrice($machineName)) * $amount;
        }

        return $value;
    }

    private function metalEquivalent(Resources $resources): float
    {
        return $resources->metal->get()
            + self::CRYSTAL_WEIGHT * $resources->crystal->get()
            + self::DEUTERIUM_WEIGHT * $resources->deuterium->get();
    }

    /**
     * The multiple of a held fleet's value its wall has to reach, from the behaviour file. A file
     * without the number states no ambush at all, which is an error rather than a silent default.
     */
    private function baitRatio(): float
    {
        if ($this->ratio !== null) {
            return $this->ratio;
        }

        $parsed = Yaml::parseFile($this->policyFile ?? module_path('AI', self::POLICY_FILE));
        $ratio = is_array($parsed) ? ($parsed['bait_ratio'] ?? null) : null;

        if (! is_numeric($ratio) || (float) $ratio <= 0.0) {
            throw new RuntimeException('threat-response: the file must state a positive bait_ratio.');
        }

        return $this->ratio = (float) $ratio;
    }
}
