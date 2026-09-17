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
    });

// The coalition campaign page is a read-only situation log: any logged-in player can read it,
// and it writes nothing, so it sits behind the ordinary in-game middleware, not the staff gate.
Route::middleware(['auth', 'banned', 'globalgame', 'locale', 'firstlogin'])
    ->prefix('campaign')
    ->name('campaign.')
    ->group(function (): void {
        Route::get('/', [CampaignController::class, 'index'])->name('index');
    });
