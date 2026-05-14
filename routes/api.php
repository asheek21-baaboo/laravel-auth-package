<?php

declare(strict_types=1);

use Baaboo\InternalToolComposerAuthPackage\Http\Controllers\MeController;
use Illuminate\Support\Facades\Route;

Route::middleware('company.auth')
    ->get('/me', MeController::class)
    ->name('company-auth.me');
