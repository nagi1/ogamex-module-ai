<?php

declare(strict_types=1);

namespace Modules\AI\Support;

/**
 * Advisory alliance-founding steps drawn from the community wiki.
 *
 * The source is a tips page rather than official mechanics, it states no figures,
 * and no existing AI principle covers alliance founding. This object therefore
 * carries plain guidance only: it asserts nothing about server mechanics and
 * encodes no number, so any numeric rule must still be confirmed by the host.
 */
final class AllianceCreationChecklist
{
    /**
     * Steps in the order the source lists them.
     *
     * @return array<string, string> step key => guidance text
     */
    public function steps(): array
    {
        return [
            'tag' => 'Pick a short tag that other players can recognise at a glance.',
            'name' => 'Pick a name that describes the alliance you want to build.',
            'recruitment_message' => 'Write a message that explains what the alliance offers and who it welcomes.',
            'rank_assignment' => 'Assign ranks so every member knows who handles what.',
            'diplomacy_stance' => 'Decide which alliances you treat as friends and which you do not.',
        ];
    }
}
