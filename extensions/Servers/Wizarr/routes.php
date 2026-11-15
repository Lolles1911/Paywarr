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

// "Report a problem" with a movie or show: becomes a support ticket. For customers with requests.
Route::middleware(['web', 'auth'])->prefix('wizarr/report')->name('extensions.wizarr.report')->group(function () {
    Route::get('/', [Wizarr::class, 'reportPage']);
    Route::post('/', [Wizarr::class, 'reportSubmit'])->name('.submit');
    Route::get('/poster/{service}', [Wizarr::class, 'reportPoster'])->whereNumber('service')->middleware('throttle:120,1')->name('.poster');
});

// The Requests tab: search, request, discover, my requests. For customers with requests.
Route::middleware(['web', 'auth'])->prefix('wizarr/requests')->name('extensions.wizarr.requests')->group(function () {
    Route::get('/', [Wizarr::class, 'requestsPage']);
    Route::post('/', [Wizarr::class, 'requestsSubmit'])->name('.submit');
});
