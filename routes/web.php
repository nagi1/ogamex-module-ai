<?php

use Illuminate\Support\Facades\Route;
use Modules\AI\Http\Controllers\AIController;
use Modules\AI\Http\Controllers\CampaignController;
use Modules\AI\Http\Controllers\HarnessStatusController;
use Modules\AI\Http\Controllers\RlTrainingStatusController;

// The harness page watches the build-time pipeline, not the game, so it sits outside the player auth
// gate and is refused outright unless the app is local.
Route::prefix('ai-harness')->name('ai.harness.')->group(function (): void {
    Route::get('/', [HarnessStatusController::class, 'index'])->name('index');
    Route::get('/poll', [HarnessStatusController::class, 'poll'])->name('poll');
    // The last hour of harness output, from the persistent timestamped log.
    Route::get('/log', [HarnessStatusController::class, 'log'])->name('log');
    // The whole ledger, read-only: the overview counts rows, this lets a person read them.
    Route::get('/tasks', [HarnessStatusController::class, 'tasks'])->name('tasks');
    // The learned-economy-policy run (data, training, closed loop), read from the collector's status file.
    Route::get('/rl', [RlTrainingStatusController::class, 'index'])->name('rl');
    Route::get('/rl/poll', [RlTrainingStatusController::class, 'poll'])->name('rl.poll');
});

Route::middleware(['auth', 'banned', 'globalgame', 'locale', 'firstlogin', 'admin'])
    ->prefix('admin/ai')
    ->name('ai.')
    ->group(function (): void {
        Route::get('/', [AIController::class, 'index'])->name('index');
        // The staff switch is a POST because it changes module state, and every operator
        // action on this page has to leave a record of who did it.
        Route::post('/switch', [AIController::class, 'switch'])->name('switch');
        // Live settings write the host settings table; deployment settings stay in the YAML file.
        Route::post('/settings', [AIController::class, 'settings'])->name('settings');
        // The LLM budget writes the same host settings table; the page is a focused surface, not a second store.
        Route::post('/llm', [AIController::class, 'llm'])->name('llm');
        // Operations are queued, never run inline; the POST only records and dispatches.
        Route::post('/operations', [AIController::class, 'operations'])->name('operations');
        // Campaign controls are queued and audited the same way; open/declare validate inputs here.
        Route::post('/campaigns', [AIController::class, 'campaigns'])->name('campaigns');
        // Stopping one account is a smaller move than the population switch, but it is still a
        // state change and records its own who, why and when.
        Route::post('/account/switch', [AIController::class, 'switchAccount'])->name('account.switch');
        // The per-account drill-down is a read: the board links to it, it writes nothing.
        Route::get('/account/{player}', [AIController::class, 'account'])->name('account');
    });

// The coalition campaign page is a read-only situation log: any logged-in player can read it,
// and it writes nothing, so it sits behind the ordinary in-game middleware, not the staff gate.
Route::middleware(['auth', 'banned', 'globalgame', 'locale', 'firstlogin'])
    ->prefix('campaign')
    ->name('campaign.')
    ->group(function (): void {
        Route::get('/', [CampaignController::class, 'index'])->name('index');
    });
