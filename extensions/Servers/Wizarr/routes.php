<?php

use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Servers\Wizarr\Wizarr;

// Loaded from Wizarr::boot(). Customer pages for connecting a Discord account.
Route::middleware(['web', 'auth'])->prefix('wizarr/discord')->name('extensions.wizarr.discord')->group(function () {
    Route::get('/', [Wizarr::class, 'discordPage']);
    Route::get('/connect', [Wizarr::class, 'discordConnect'])->name('.connect');
    Route::post('/disconnect', [Wizarr::class, 'discordDisconnect'])->name('.disconnect');
});

// "Log ind med Discord" for guests; it creates the account when needed.
Route::middleware(['web', 'guest'])
    ->get('/wizarr/discord/login', [Wizarr::class, 'discordLogin'])
    ->name('extensions.wizarr.discord.login');

// One callback for both linking (logged in) and signing in (guest), so the
// Discord application needs a single redirect URL.
Route::middleware(['web'])
    ->get('/wizarr/discord/callback', [Wizarr::class, 'discordCallback'])
    ->name('extensions.wizarr.discord.callback');

// Polled by the "your access is ready" banner. Throttled: it asks every 3 seconds at most.
Route::middleware(['web', 'auth', 'throttle:40,1'])
    ->get('/wizarr/status', [Wizarr::class, 'bannerStatus'])
    ->name('extensions.wizarr.status');
