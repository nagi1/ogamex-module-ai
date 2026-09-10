<?php

use Illuminate\Support\Facades\Route;
use Modules\AI\Http\Controllers\AIController;

Route::middleware(['auth', 'banned', 'globalgame', 'locale', 'firstlogin', 'admin'])
    ->prefix('admin/ai')
    ->name('ai.')
    ->group(function (): void {
        Route::get('/', [AIController::class, 'index'])->name('index');
    });
