<?php

namespace Modules\AI\Actions;

use Exception;
use Modules\AI\Enums\AiAllianceStance;
use Modules\AI\Models\AiAllianceRelation;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiRelationship;
use OGame\Models\Alliance;
use OGame\Models\User;
use OGame\Services\AllianceService;
use Symfony\Component\Yaml\Yaml;

/**
 * An AI-led alliance declares war on the alliance its members hold the most grievance against (alliance-politics.md).
 * Nothing stages it: the grievance is the threat members recorded after being raided, so a war starts where empires
 * have been hitting each other, human or AI. The side with the larger grievance declares; the leader tells its
 * members, the other leader and the board, the way players announce a war in OGame.
 */
class DeclareAiAllianceWarsAction
{
    private const FILE = '/resources/behavior/alliance-war.yaml';

    public function handle(): int
    {
        $policy = $this->policy();
        $line = (float) ($policy['war_grievance'] ?? 1.5);
        $founders = AiProfile::query()->where('enabled', true)->pluck('player_id')->all();
        $declared = 0;

        foreach (Alliance::query()->whereIn('founder_user_id', $founders)->orderBy('id')->get() as $alliance) {
            $target = $this->worstGrievance((int) $alliance->id, $line);
            if ($target === null) {
                continue;
            }

            $this->declare($alliance, $target, $policy);
            $declared++;
        }

        return $declared;
    }

    /** The other alliance this one holds the largest grievance against, when past the line, larger than theirs and not already at war. */
    private function worstGrievance(int $allianceId, float $line): Alliance|null
    {
        $members = $this->members($allianceId);
        $grievances = [];

        foreach (Alliance::query()->whereKeyNot($allianceId)->pluck('id') as $otherId) {
            $theirs = $this->members((int) $otherId);
            $mine = $this->grievance($members, $theirs);
            if ($mine >= $line && $mine >= $this->grievance($theirs, $members)
                && !AiAllianceRelation::query()->openBetween($allianceId, (int) $otherId)->where('stance', AiAllianceStance::War)->exists()) {
                $grievances[(int) $otherId] = $mine;
            }
        }

        if ($grievances === []) {
            return null;
        }

        arsort($grievances);

        return Alliance::query()->find(array_key_first($grievances));
    }

    /**
     * @param list<int> $from
     * @param list<int> $toward
     */
    private function grievance(array $from, array $toward): float
    {
        return (float) AiRelationship::query()->whereIn('player_id', $from)->whereIn('other_player_id', $toward)->sum('threat');
    }

    /** @return list<int> */
    private function members(int $allianceId): array
    {
        return User::query()->where('alliance_id', $allianceId)->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    }

    /** @param array<string, mixed> $policy */
    private function declare(Alliance $alliance, Alliance $enemy, array $policy): void
    {
        $founderId = (int) $alliance->founder_user_id;
        $lines = (array) ($policy['lines'] ?? []);
        $say = static fn (string $key): string => str_replace('{tag}', (string) $enemy->alliance_tag, (string) ($lines[$key] ?? ''));

        // A pact between the two ends the moment one declares: that is the betrayal the other side remembers.
        AiAllianceRelation::query()->openBetween((int) $alliance->id, (int) $enemy->id)->update(['ended_at' => now()]);
        AiAllianceRelation::query()->create([
            'alliance_id' => $alliance->id,
            'other_alliance_id' => $enemy->id,
            'stance' => AiAllianceStance::War,
            'cause' => 'grievance',
            'declared_by' => $founderId,
            'started_at' => now(),
        ]);

        // The announcements are the visible half; the host refusing one (permission, length) leaves the war standing.
        $service = app(AllianceService::class);
        try {
            $service->sendBroadcastMessage((int) $alliance->id, $founderId, $say('war_broadcast'), ['-1']);
            $external = trim((string) $alliance->external_text . "\n" . $say('war_external'));
            $service->updateTexts((int) $alliance->id, $founderId, $alliance->internal_text, $external, $alliance->application_text);
        } catch (Exception) {
        }

        if ($enemy->founder_user_id !== null) {
            app(DeliverAiDirectReplyAction::class)->handle($founderId, (int) $enemy->founder_user_id, $say('war_to_leader'));
        }
    }

    /** @return array<string, mixed> */
    private function policy(): array
    {
        $file = Yaml::parseFile(dirname(__DIR__, 2) . self::FILE);

        return is_array($file) ? $file : [];
    }
}
