<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Str;
use Modules\AI\Enums\AiArchetype;
use Modules\AI\Enums\AiSkillBand;
use Modules\AI\Enums\AiWorkKind;
use Modules\AI\Enums\AiWorkState;
use Modules\AI\Jobs\ProcessAiWork;
use Modules\AI\Models\AiProfile;
use Modules\AI\Models\AiWorkItem;
use OGame\Models\Planet;

/**
 * Drives one real ProcessAiWork job for the end-to-end container trial.
 *
 * Run inside the application container with the trial's throwaway status file, so the
 * module is enabled exactly as a real install leaves it:
 *
 *   QUEUE_CONNECTION=redis php e2e-dispatch-ai-job.php dispatch /tmp/e2e-ai-on.json
 *   php e2e-dispatch-ai-job.php state 12345
 *   php e2e-dispatch-ai-job.php cleanup 12345 678
 *
 * `dispatch` queues a genuine job on the module's own "ai" lane — a lane that only
 * exists while the module is enabled — so a later state change proves the whole chain:
 * Redis queue -> Horizon supervisor-ai -> module job class -> module tables.
 *
 * The work item belongs to a real player and planet, and the profile is only created
 * when that player has none, so the trial can clean up after itself completely.
 */

$mode = $argv[1] ?? '';

if ($mode === '') {
    fwrite(STDERR, "usage: e2e-dispatch-ai-job.php {dispatch <statuses-file>|state <work-item-id>|cleanup <work-item-id> <profile-id>}\n");

    exit(2);
}

// The module must look enabled while this process boots, exactly like a real install.
if ($mode === 'dispatch' && isset($argv[2])) {
    putenv("MODULES_STATUSES_FILE={$argv[2]}");
    $_ENV['MODULES_STATUSES_FILE'] = $argv[2];
    $_SERVER['MODULES_STATUSES_FILE'] = $argv[2];
}

$root = dirname(__DIR__, 3);

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if ($mode === 'dispatch') {
    return dispatchJob();
}

if ($mode === 'state') {
    return reportState();
}

if ($mode === 'cleanup') {
    return cleanup();
}

fwrite(STDERR, "Unknown mode [{$mode}].\n");

exit(2);

function dispatchJob(): int
{
    $planet = Planet::query()->where('destroyed', false)->orderBy('id')->first();

    if ($planet === null) {
        fwrite(STDERR, "No live planet exists, so no AI work can be dispatched.\n");

        return 1;
    }

    $profile = AiProfile::query()->where('player_id', $planet->user_id)->first();
    $profileCreated = 0;

    if ($profile === null) {
        $profile = AiProfile::query()->create([
            'player_id' => $planet->user_id,
            'archetype' => AiArchetype::Miner->value,
            'skill_band' => AiSkillBand::Standard->value,
            'random_seed' => 42,
            'enabled' => true,
        ]);
        $profileCreated = 1;
    }

    $workItem = AiWorkItem::query()->create([
        'player_id' => $planet->user_id,
        'kind' => AiWorkKind::BuildFirstBuilding->value,
        'due_at' => now(),
        'payload' => ['planet_id' => $planet->id],
        'idempotency_key' => 'e2e:'.Str::random(24),
        'state' => AiWorkState::Pending->value,
    ]);

    // The caller sets QUEUE_CONNECTION=redis; the job itself pins the module's "ai"
    // queue in its constructor, which is the lane Horizon provisions for this module.
    ProcessAiWork::dispatch($workItem->id);

    printf(
        "work_item_id=%d profile_id=%d profile_created=%d player_id=%d planet_id=%d\n",
        $workItem->id,
        $profile->id,
        $profileCreated,
        $planet->user_id,
        $planet->id,
    );

    return 0;
}

function reportState(): int
{
    $workItem = AiWorkItem::query()->find((int) ($GLOBALS['argv'][2] ?? 0));

    if ($workItem === null) {
        fwrite(STDERR, "Work item not found.\n");

        return 1;
    }

    printf("state=%d attempts=%d\n", (int) $workItem->state->value, $workItem->attempts);

    return 0;
}

function cleanup(): int
{
    $workItemId = (int) ($GLOBALS['argv'][2] ?? 0);
    $profileId = (int) ($GLOBALS['argv'][3] ?? 0);

    AiWorkItem::query()->whereKey($workItemId)->delete();

    if ($profileId > 0) {
        AiProfile::query()->whereKey($profileId)->delete();
    }

    echo "cleaned\n";

    return 0;
}
