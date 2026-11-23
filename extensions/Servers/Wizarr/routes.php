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

// A gift card: send its code to a friend.
Route::middleware(['web', 'auth', 'throttle:30,1'])->name('extensions.wizarr.gift')->group(function () {
    Route::get('wizarr/gift/{service}', [Wizarr::class, 'giftPage'])->whereNumber('service');
    Route::post('wizarr/gift/{service}', [Wizarr::class, 'giftPage'])->whereNumber('service');
});

// Customers pause their subscription themselves, and resume (Loyalty).
Route::middleware(['web', 'auth', 'throttle:30,1'])->name('extensions.wizarr.pause')->group(function () {
    Route::get('wizarr/pause/{service}', [Wizarr::class, 'pausePage'])->whereNumber('service');
    Route::post('wizarr/pause/{service}', [Wizarr::class, 'pausePage'])->whereNumber('service');
});

// "Invite a friend": the customer's own code (Loyalty).
Route::middleware(['web', 'auth', 'throttle:30,1'])->name('extensions.wizarr.invite')->group(function () {
    Route::get('wizarr/invite', [Wizarr::class, 'referralPage']);
    Route::post('wizarr/invite', [Wizarr::class, 'referralPage']);
});

// Discord's slash commands (/status, /newlink, /stats, /password). Signed by Discord; no session or CSRF.
Route::middleware(['throttle:120,1'])
    ->post('/wizarr/discord/interactions', [Wizarr::class, 'discordInteraction'])
    ->name('extensions.wizarr.discord.interactions');

// "New on the server" mails: stop getting them (a signed link in the mail).
Route::middleware(['web', 'signed'])
    ->get('/wizarr/news/off/{user}', [Wizarr::class, 'newsUnsubscribe'])->whereNumber('user')
    ->name('extensions.wizarr.news.off');

// Service status: every media server, up or down, and notices. For everyone.
Route::middleware(['web', 'throttle:60,1'])
    ->get('/status', [Wizarr::class, 'statusPage'])
    ->name('extensions.wizarr.status-page');

// Help: guides per device and questions. "Getting started" can be hidden.
Route::middleware(['web', 'auth'])->get('/wizarr/help', [Wizarr::class, 'helpPage'])->name('extensions.wizarr.help');
Route::middleware(['web', 'auth'])->post('/wizarr/checklist/close', [Wizarr::class, 'closeChecklist'])->name('extensions.wizarr.checklist.close');

// The customer changes their media server.
Route::middleware(['web', 'auth', 'throttle:30,1'])->name('extensions.wizarr.switch')->group(function () {
    Route::get('wizarr/switch/{service}', [Wizarr::class, 'switchPage'])->whereNumber('service');
    Route::post('wizarr/switch/{service}', [Wizarr::class, 'switchPage'])->whereNumber('service');
});

// Satisfaction: one click in the mail (signed), then a comment; and why they cancelled.
Route::middleware(['web', 'signed', 'throttle:30,1'])->name('extensions.wizarr.nps')->group(function () {
    Route::get('wizarr/nps/{user}/{score}', [Wizarr::class, 'npsAnswer'])->whereNumber('user')->whereNumber('score');
    Route::post('wizarr/nps/{user}/{score}', [Wizarr::class, 'npsAnswer'])->whereNumber('user')->whereNumber('score');
});
Route::middleware(['web', 'signed', 'throttle:30,1'])
    ->get('wizarr/why/{cancellation}/{reason}', [Wizarr::class, 'whyAnswer'])->whereNumber('cancellation')
    ->name('extensions.wizarr.why');

// Movie night: what's planned, "I'm coming", and where to watch.
Route::middleware(['web', 'auth', 'throttle:30,1'])->name('extensions.wizarr.party')->group(function () {
    Route::get('wizarr/movie-night', [Wizarr::class, 'partyPage']);
    Route::post('wizarr/movie-night', [Wizarr::class, 'partyPage']);
});

// A challenge: the customer's progress and the leaderboard.
Route::middleware(['web', 'auth'])->get('wizarr/challenge', [Wizarr::class, 'challengePage'])->name('extensions.wizarr.challenge');

// Friend pass: a free week for a friend, once a month.
Route::middleware(['web', 'auth', 'throttle:20,1'])->name('extensions.wizarr.pass')->group(function () {
    Route::get('wizarr/friend-pass', [Wizarr::class, 'passPage']);
    Route::post('wizarr/friend-pass', [Wizarr::class, 'passPage']);
});

// "My <site name>": everything for the customer's subscription on one page.
Route::middleware(['web', 'auth'])->get('wizarr/me', [Wizarr::class, 'hubPage'])->name('extensions.wizarr.hub');

// Posters Paywarr has listed (My page, the front page, /catalog). Public: no titles in the address.
Route::middleware(['web', 'throttle:300,1'])->get('wizarr/poster/{key}', [Wizarr::class, 'posterImage'])->where('key', '[0-9a-f]{16}')->name('extensions.wizarr.poster');

// The selection for visitors (with "Show the selection to visitors").
Route::middleware(['web', 'throttle:60,1'])->get('catalog', [Wizarr::class, 'catalogPage'])->name('extensions.wizarr.catalog');

// The mail list checkbox at checkout.
Route::middleware(['web', 'auth', 'throttle:30,1'])->post('wizarr/mail-list', [Wizarr::class, 'mailListToggle'])->name('extensions.wizarr.mail-list');

// A friend's link: remembers their code for the cart, then the shop. For visitors too.
Route::middleware(['web', 'throttle:30,1'])->get('wizarr/join/{code}', [Wizarr::class, 'joinPage'])->where('code', '[A-Za-z0-9-]{3,40}')->name('extensions.wizarr.join');
