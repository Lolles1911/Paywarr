<?php

namespace Paymenter\Extensions\Servers\Wizarr\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

/** Run by Paymenter's queue worker: notes that the queue moves (Wizarr::queueHeartbeat()). */
class QueueHeartbeat implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(): void
    {
        Cache::put('paywarr:queue:ran', time(), 7 * 86400);
    }
}
