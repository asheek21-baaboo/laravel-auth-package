<?php

declare(strict_types=1);

use Baaboo\InternalToolComposerAuthPackage\Http\Controllers\AuthCallbackController;
use Baaboo\InternalToolComposerAuthPackage\Http\Controllers\TokenExpiredController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'throttle:60,1'])
    ->group(function (): void {
        Route::get('/auth/token-expired', TokenExpiredController::class)
            ->name('company-auth.token-expired');
    });

Route::middleware(['web', 'throttle:20,1'])
    ->group(function (): void {
        Route::get('/auth/callback', AuthCallbackController::class)
            ->name('company-auth.callback');
    });
