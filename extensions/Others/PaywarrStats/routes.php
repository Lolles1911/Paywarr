<?php

use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Others\PaywarrStats\PaywarrStats;

// Loaded from PaywarrStats::boot(). The customer's own statistics.
Route::middleware(['web', 'auth'])->get('/stats', [PaywarrStats::class, 'page'])->name('extensions.paywarr-stats');
