<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Modules\AI\Enums\AiAllianceStance;

/**
 * A war or pact between two alliances, open while ended_at is null.
 *
 * @property int $alliance_id
 * @property int $other_alliance_id
 * @property AiAllianceStance $stance
 * @property string $cause
 * @property int|null $declared_by
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
 * @property Carbon|null $notice_ends_at
 * @property int|null $cancelled_by_alliance_id
 */
#[Unguarded]
class AiAllianceRelation extends Model
{
    protected function casts(): array
    {
        return [
            'stance' => AiAllianceStance::class,
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'notice_ends_at' => 'datetime',
        ];
    }

    /**
     * The open relations either side of the pair holds, whichever declared it.
     *
     * @param Builder<self> $query
     * @return Builder<self>
     */
    #[Scope]
    protected function openBetween(Builder $query, int $allianceId, int $otherAllianceId): Builder
    {
        return $query->whereNull('ended_at')->where(fn (Builder $pair) => $pair
            ->where(fn (Builder $one) => $one->where('alliance_id', $allianceId)->where('other_alliance_id', $otherAllianceId))
            ->orWhere(fn (Builder $other) => $other->where('alliance_id', $otherAllianceId)->where('other_alliance_id', $allianceId)));
    }

    /**
     * The alliances the given one holds an open stance toward, whichever side declared it.
     *
     * @return list<int>
     */
    public static function counterparts(int $allianceId, AiAllianceStance $stance): array
    {
        return self::query()->whereNull('ended_at')->where('stance', $stance)
            ->where(fn (Builder $side) => $side->where('alliance_id', $allianceId)->orWhere('other_alliance_id', $allianceId))
            ->get(['alliance_id', 'other_alliance_id'])
            ->map(static fn (self $relation): int => $relation->alliance_id === $allianceId ? $relation->other_alliance_id : $relation->alliance_id)
            ->unique()->values()->all();
    }
}
