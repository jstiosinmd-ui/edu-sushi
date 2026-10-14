<?php

namespace App\Jobs;

use App\Services\CatchReminders;
use App\Services\PushTransport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendCatchReminder implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public int $reminderId) {}

    public function handle(CatchReminders $reminders, PushTransport $transport): void
    {
        $reminders->send($this->reminderId, $transport);
    }
}
