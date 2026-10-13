<?php

use App\Models\Attempt;
use App\Services\Study;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('sushi:expire', function (Study $study) {
    Attempt::where('status', 'active')->whereNotNull('deadline')->where('deadline', '<=', now())->chunkById(100, function ($rows) use ($study) {
        foreach ($rows as $a) {
            $study->expire($a);
        }
    });
    $this->info('Expired attempts finalized.');
});
Schedule::command('sushi:expire')->everyMinute()->withoutOverlapping();
