<?php

use Illuminate\Support\Facades\Route;
use Modules\AI\Http\Controllers\AIController;
use Modules\AI\Http\Controllers\CampaignController;

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
