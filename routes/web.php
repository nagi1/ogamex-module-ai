<?php

use Illuminate\Support\Facades\Route;
use Modules\AI\Http\Controllers\AIController;

Route::middleware(['auth', 'banned', 'globalgame', 'locale', 'firstlogin', 'admin'])
    ->prefix('admin/ai')
    ->name('ai.')
    ->group(function (): void {
        Route::get('/', [AIController::class, 'index'])->name('index');
        // The staff switch is a POST because it changes module state, and every operator
        // action on this page has to leave a record of who did it.
        Route::post('/switch', [AIController::class, 'switch'])->name('switch');
    });
