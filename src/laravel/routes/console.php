<?php

use App\Models\Attempt;
use App\Models\FlashcardDeckItem;
use App\Models\User;
use App\Services\CatchReminders;
use App\Services\Learning;
use App\Services\Passport;
use App\Services\Study;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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

Artisan::command('sushi:remind', function (CatchReminders $reminders) {
    $this->info($reminders->dispatchDue().' daily reminder(s) queued.');
});
Schedule::command('sushi:remind')->everyMinute()->withoutOverlapping();

Artisan::command('sushi:backfill-community', function (Learning $learning) {
    Attempt::with('items')->orderBy('id')->chunkById(100, function ($attempts) use ($learning) {
        foreach ($attempts as $a) {
            foreach ($a->items as $item) {
                if ($item->answer !== null && $item->graded_at) {
                    $learning->answer($a->user_id, 'attempt:'.$item->id, $item->snapshot, $a->mode, $item->correct, $item->graded_at, false);
                }
            }
            if ($a->finished_at) {
                $learning->completed($a);
            }
        }
    });
    DB::table('flashcard_reviews')->whereNotNull('answer')->orderBy('id')->chunkById(200, function ($rows) use ($learning) {
        foreach ($rows as $row) {
            $item = FlashcardDeckItem::find($row->flashcard_deck_item_id);
            if ($item) {
                $learning->answer($row->user_id, 'memory:'.$row->review_token, $item->snapshot, 'memory', $row->correct, $row->created_at, false);
            }
        }
    });
    User::each(fn ($u) => app(Passport::class)->for($u));
    $this->info('Recorded answer and completion history reconciled. Unknown feedback views and historical bookmarks were not inferred.');
});
