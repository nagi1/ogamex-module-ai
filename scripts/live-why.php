<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Modules\AI\Domain\Decision\DecisionEngine;
use Modules\AI\Domain\Perception\PlayerObservationService;
use Modules\AI\Domain\Perception\PlayerPerceptionBuilder;
use Modules\AI\Models\AiProfile;

/**
 * Why a live account would or would not do something right now: the observation its next session will
 * see, every input flag in it, and the real decision engine run over it, without writing anything.
 *
 * DEV TOOLING, read-only. A live situation that fails says "no spy"; this says whether spying was ever
 * on the table (the published capability, the free fleet slots, the target reports), and if it was,
 * what outscored it or why the candidate factory turned it down. Gate 1 holds: every flag is printed
 * as the host-backed observation names it, nothing here lists actions by hand.
 *
 *   php Modules/AI/scripts/live-why.php PLAYER_ID|subject
 */

$root = dirname(__DIR__, 3);

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// "subject" is the account a cohort situation acts on (cohort-scenario.php: the first enabled profile).
$playerId = ($argv[1] ?? '') === 'subject'
    ? (int) AiProfile::query()->where('enabled', true)->orderBy('player_id')->value('player_id')
    : (int) ($argv[1] ?? 0);
$profile = AiProfile::query()->where('player_id', $playerId)->first();
if ($profile === null) {
    echo "WHY: $playerId is not an AI account in this universe\n";

    exit(1);
}

$observation = app(PlayerObservationService::class)->ownedState($playerId);
echo "WHY $playerId ({$profile->archetype->name}): what the next session will see\n\n";

$actions = (array) ($observation['available_actions'] ?? []);
echo '  published: '.implode(', ', array_keys(array_filter($actions)))."\n";
echo '  NOT published: '.(implode(', ', array_keys(array_diff_key($actions, array_filter($actions)))) ?: 'nothing')."\n\n";

foreach ($observation as $key => $value) {
    if (in_array($key, ['available_actions', 'planets'], true)) {
        continue;
    }
    $shown = is_array($value) ? count($value).' item(s)'.($value === [] ? '' : ': '.substr(json_encode(array_slice($value, 0, 3)), 0, 240)) : json_encode($value);
    printf("  %-28s %s\n", $key, $shown);
}
printf("  %-28s %d planet(s)\n", 'planets', count((array) ($observation['planets'] ?? [])));

$perception = app(PlayerPerceptionBuilder::class)->fromObservation($observation);
$trace = app(DecisionEngine::class)->decide($profile, $perception, 'why:'.$playerId.':'.time());

echo "\n  decision: ".$trace->selected->candidate->type->name."\n";
foreach ($trace->candidates as $scored) {
    $parts = [];
    foreach (array_filter($scored->components) as $component => $value) {
        $parts[] = $component.'='.round((float) $value, 1);
    }
    printf("    %-12s %5.1f  %s\n", $scored->candidate->type->name, $scored->score, implode(', ', $parts));
}
echo '  turned down by the candidate factory: '.($trace->rejections === [] ? 'nothing' : '')."\n";
foreach ($trace->rejections as $key => $reason) {
    echo "    $key: $reason\n";
}
