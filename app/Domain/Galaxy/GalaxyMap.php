<?php

declare(strict_types=1);

namespace Modules\AI\Domain\Galaxy;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\AI\Infrastructure\Battle\NativeRaidEstimator;
use OGame\Models\EspionageReport;
use OGame\Models\Message;
use OGame\Models\Resources;
use OGame\Services\ObjectService;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * What one system looks like from the account's own intel: how much military it has seen standing there
 * (threat) and how much loot its reports showed (opportunity), so a colony site or an errand can be chosen
 * with an eye on the neighbourhood instead of blind (architecture step 7, 4.5).
 *
 * Only what the account has been told counts. The reports are reached through the account's own inbox
 * rows, the same link every other intel reader uses, so nothing here reads a live foreign planet
 * (diagnosis 2.5) and no object is named: the units a report lists are priced through the host's own
 * catalogue and its resources through the estimator's weights. The window, the scale, the bound and the
 * share open loot is worth are read by name from `resources/behavior/intel.yaml`.
 */
final class GalaxyMap
{
    private const POLICY_FILE = '/resources/behavior/intel.yaml';

    /** @var array<string, mixed>|null */
    private static ?array $policy = null;

    /** The military this account has seen in one system, in map points; zero for a system it never probed. */
    public function threat(int $playerId, int $galaxy, int $system): int
    {
        $value = 0.0;
        foreach ($this->seen($playerId, $galaxy, $system) as $report) {
            $value += $this->unitsValue($report->ships ?? []) + $this->unitsValue($report->defense ?? []);
        }

        return $this->points($value);
    }

    /** The loot this account has seen in one system, in map points, discounted for the fights it sits behind. */
    public function opportunity(int $playerId, int $galaxy, int $system): int
    {
        $value = 0.0;
        foreach ($this->seen($playerId, $galaxy, $system) as $report) {
            $value += $this->isDefended($report) ? $this->lootValue($report) * $this->share('galaxy_map.defended_opportunity_share') : $this->lootValue($report);
        }

        return $this->points($value);
    }

    /**
     * The reports this account has received on the system, newest per position: the map is the account's
     * last look at a body, not the sum of every probe it has ever sent.
     *
     * @return Collection<int, EspionageReport>
     */
    private function seen(int $playerId, int $galaxy, int $system): Collection
    {
        $reportIds = Message::query()
            ->where('user_id', $playerId)
            ->whereNotNull('espionage_report_id')
            ->where('created_at', '>=', now()->subHours($this->int('galaxy_map.report_window_hours', 168)))
            ->pluck('espionage_report_id');

        if ($reportIds->isEmpty()) {
            return collect();
        }

        return EspionageReport::query()
            ->whereIn('id', $reportIds)
            ->where('planet_galaxy', $galaxy)
            ->where('planet_system', $system)
            ->orderByDesc('id')
            ->get(['planet_position', 'resources', 'ships', 'defense'])
            ->unique('planet_position')
            ->values();
    }

    /** @param array<string, int>|null $units */
    private function unitsValue(?array $units): float
    {
        $value = 0.0;

        foreach ($units ?? [] as $machineName => $amount) {
            $value += $this->unitValue((string) $machineName) * (float) $amount;
        }

        return $value;
    }

    /**
     * One hull on the estimator's own weights. A report can name a hull the universe no longer has, which
     * prices at nothing rather than failing the whole read.
     */
    private function unitValue(string $machineName): float
    {
        try {
            return app(NativeRaidEstimator::class)->metalEquivalent(ObjectService::getObjectRawPrice($machineName));
        } catch (Throwable) {
            return 0.0;
        }
    }

    private function lootValue(EspionageReport $report): float
    {
        $resources = $report->resources ?? [];

        return app(NativeRaidEstimator::class)->metalEquivalent(new Resources(
            (int) ($resources['metal'] ?? 0),
            (int) ($resources['crystal'] ?? 0),
            (int) ($resources['deuterium'] ?? 0),
        ));
    }

    /** [] is "probed and empty": loot standing in the open, not loot behind a fight. */
    private function isDefended(EspionageReport $report): bool
    {
        return ($report->ships ?? []) !== [] || ($report->defense ?? []) !== [];
    }

    /**
     * The metal-equivalent of a system in map points, bounded: the scale keeps a rich system and a poor one
     * apart, the ceiling stops one report from dwarfing the map, and no system ever reads below zero.
     */
    private function points(float $value): int
    {
        $scale = max(1, $this->int('galaxy_map.scale', 10_000));

        return max(0, (int) min($this->int('galaxy_map.ceiling', PHP_INT_MAX), (int) floor($value / $scale)));
    }

    private function share(string $path): float
    {
        return max(0.0, min(1.0, $this->number($path, 0.0)));
    }

    private function int(string $path, int $fallback): int
    {
        return (int) $this->number($path, (float) $fallback);
    }

    private function number(string $path, float $fallback): float
    {
        $value = $this->policy();

        foreach (explode('.', $path) as $segment) {
            $value = is_array($value) ? ($value[$segment] ?? null) : null;
        }

        return is_numeric($value) ? (float) $value : $fallback;
    }

    /** @return array<string, mixed> */
    private function policy(): array
    {
        if (self::$policy !== null) {
            return self::$policy;
        }

        $path = dirname(__DIR__, 3) . self::POLICY_FILE;
        $parsed = is_file($path) ? Yaml::parseFile($path) : [];

        return self::$policy = is_array($parsed) ? $parsed : [];
    }
}
