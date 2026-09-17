<?php

namespace Modules\AI\Domain\Decision;

use Modules\AI\Models\AiPhalanxScan;
use Modules\AI\Models\AiProfile;
use OGame\Factories\PlanetServiceFactory;
use OGame\Factories\PlayerServiceFactory;
use OGame\Models\Enums\PlanetType;
use OGame\Models\EspionageReport;
use OGame\Models\Message;
use OGame\Models\Planet;
use OGame\Models\Planet\Coordinate;
use OGame\Models\User;
use OGame\Services\PhalanxService;
use OGame\Services\PlayerService;

/**
 * Answers whether this account can phalanx-scan a raid target now, and which one.
 *
 * A fleeter with a moon scans a target before committing — that is ordinary play and
 * the only way to see a fleet the target is holding inside its planet, which the
 * espionage report cannot show. The scan needs an owned moon with a sensor phalanx
 * and a recent espionage report whose target is inside the phalanx range; the host's
 * own `canScanTarget` is the only legality gate, respected exactly by the action.
 *
 * Each target is scanned once per window: the scan is a point-in-time read, and the
 * raid decision consults the stored result instead of re-scanning the same planet.
 */
class QueueablePhalanxPlanner
{
    /** Reports this old no longer name a target worth scanning. */
    private const INTEL_TTL_HOURS = 24;

    /** A scan stays authoritative for the raid decision this long. */
    private const SCAN_TTL_HOURS = 2;

    public function __construct(
        private PlayerServiceFactory $playerServiceFactory,
        private PlanetServiceFactory $planetServiceFactory,
        private PhalanxService $phalanx,
    ) {
    }

    public function plan(int $playerId, ?PlayerService $player = null): ?QueueablePhalanx
    {
        $profile = AiProfile::query()->where('player_id', $playerId)->where('enabled', true)->first();
        if ($profile === null) {
            return null;
        }

        if (!User::query()->whereKey($playerId)->exists()) {
            return null;
        }

        $player ??= $this->playerServiceFactory->make($playerId, true);
        $moon = $this->phalanxMoon($playerId, $player);
        if ($moon === null) {
            return null;
        }

        [$moonModel, $phalanxLevel] = $moon;
        $selection = $this->scanTarget($playerId, $moonModel, $phalanxLevel);
        if ($selection === null) {
            return null;
        }

        return app()->makeWith(QueueablePhalanx::class, [
            'moonPlanetId' => (int) $moonModel->id,
            'targetPlanetId' => $selection[1],
        ]);
    }

    /**
     * The account's first owned moon that carries a sensor phalanx.
     *
     * @return array{0: Planet, 1: int}|null [moon model, phalanx level]
     */
    private function phalanxMoon(int $playerId, PlayerService $player): ?array
    {
        $moons = Planet::query()
            ->where('user_id', $playerId)
            ->where('planet_type', PlanetType::Moon->value)
            ->get();

        foreach ($moons as $moon) {
            $service = $this->planetServiceFactory->makeForPlayer($player, $moon->id, false);
            $level = $service->getObjectLevel('sensor_phalanx');

            if ($level > 0) {
                return [$moon, $level];
            }
        }

        return null;
    }

    /**
     * The first in-range, not-yet-scanned raid target among the account's fresh reports.
     *
     * @return array{0: EspionageReport, 1: int}|null [report, target planet id]
     */
    private function scanTarget(int $playerId, Planet $moon, int $phalanxLevel): ?array
    {
        $reportIds = Message::query()
            ->where('user_id', $playerId)
            ->whereNotNull('espionage_report_id')
            ->where('created_at', '>=', now()->subHours(self::INTEL_TTL_HOURS))
            ->orderByDesc('id')
            ->limit(10)
            ->pluck('espionage_report_id');

        if ($reportIds->isEmpty()) {
            return null;
        }

        foreach (EspionageReport::query()->whereIn('id', $reportIds->all())->get() as $report) {
            $targetPlanetId = $this->planetIdAt($report);
            if ($targetPlanetId === null) {
                continue;
            }

            $coordinate = new Coordinate($report->planet_galaxy, $report->planet_system, $report->planet_position);
            if (!$this->phalanx->canScanTarget($moon->galaxy, $moon->system, $phalanxLevel, $coordinate, $playerId)) {
                continue;
            }

            if ($this->recentlyScanned($playerId, $targetPlanetId)) {
                continue;
            }

            return [$report, $targetPlanetId];
        }

        return null;
    }

    private function planetIdAt(EspionageReport $report): ?int
    {
        $id = Planet::query()
            ->where('galaxy', $report->planet_galaxy)
            ->where('system', $report->planet_system)
            ->where('planet', $report->planet_position)
            ->where('planet_type', $report->planet_type)
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    private function recentlyScanned(int $playerId, int $targetPlanetId): bool
    {
        return AiPhalanxScan::query()
            ->where('player_id', $playerId)
            ->where('target_planet_id', $targetPlanetId)
            ->where('observed_at', '>=', now()->subHours(self::SCAN_TTL_HOURS))
            ->exists();
    }
}
