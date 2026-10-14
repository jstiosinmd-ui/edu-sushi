<?php

namespace App\Services;

use App\Jobs\SendCatchReminder;
use App\Models\Attempt;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class CatchReminders
{
    public function available(User $u): bool
    {
        if (Attempt::where('user_id', $u->id)->where('mode', 'fresh')->where('status', 'active')->exists()) {
            return true;
        }
        $subjects = $u->fresh_subjects ?: Catalog::SUBJECTS;
        $pool = app(Study::class)->eligible($u->id, ['mode' => 'fresh']);

        return collect($pool)->whereIn('subject', $subjects)->unique(fn ($q) => Catalog::stemHash($q['stem']))->count() >= 10;
    }

    public function incomplete(User $u): bool
    {
        $day = now($u->timezone)->toDateString();

        return ! $u->disabled && ! DB::table('daily_catches')->where('user_id', $u->id)->where('day', $day)->exists()
            && $u->reminder_dismissed_date !== $day
            && (! $u->reminder_snoozed_until || $u->reminder_snoozed_until->lte(now()));
    }

    public function dispatchDue(): int
    {
        if (! config('sushi.push.public_key') || ! config('sushi.push.private_key')) {
            return 0;
        }
        $count = 0;
        foreach (User::where('role', 'student')->where('disabled', false)->where('reminder_enabled', true)->get() as $u) {
            $local = now($u->timezone);
            if ($local->format('H:i') < $u->reminder_time && ! $u->reminder_snoozed_until) {
                continue;
            }
            if (! $this->incomplete($u) || ! $this->available($u)) {
                continue;
            }
            if ($u->last_active_at && $u->last_active_at->gt(now()->subMinutes(2))) {
                continue;
            }
            DB::transaction(function () use ($u, $local, &$count) {
                User::whereKey($u->id)->lockForUpdate()->firstOrFail();
                if (! DB::table('push_subscriptions')->where('user_id', $u->id)->exists()) {
                    return;
                }
                $existing = DB::table('catch_reminders')->where('user_id', $u->id)->where('day', $local->toDateString())->first();
                if ($existing && $existing->status !== 'snoozed') {
                    return;
                }
                if ($existing) {
                    $id = $existing->id;
                    DB::table('catch_reminders')->where('id', $id)->update(['status' => 'queued', 'updated_at' => now()]);
                } else {
                    $id = DB::table('catch_reminders')->insertGetId(['user_id' => $u->id, 'day' => $local->toDateString(), 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
                }
                SendCatchReminder::dispatch($id)->afterCommit();
                $count++;
            });
        }

        return $count;
    }

    public function send(int $id, PushTransport $transport): void
    {
        $record = DB::table('catch_reminders')->find($id);
        if (! $record) {
            return;
        }
        $delivery = DB::transaction(function () use ($record) {
            $u = User::whereKey($record->user_id)->lockForUpdate()->first();
            $r = DB::table('catch_reminders')->where('id', $record->id)->lockForUpdate()->first();
            if (! $u || $r->status !== 'queued') {
                return;
            }
            if (! $u->reminder_enabled || ! $this->incomplete($u) || $r->day !== now($u->timezone)->toDateString() || ! $this->available($u)) {
                DB::table('catch_reminders')->where('id', $r->id)->update(['status' => 'cancelled', 'updated_at' => now()]);

                return;
            }
            if ($u->last_active_at && $u->last_active_at->gt(now()->subMinutes(2))) {
                SendCatchReminder::dispatch($r->id)->delay(now()->addMinutes(3))->afterCommit();

                return;
            }
            $sub = DB::table('push_subscriptions')->where('user_id', $u->id)->latest('updated_at')->first();
            if (! $sub) {
                DB::table('catch_reminders')->where('id', $r->id)->update(['status' => 'cancelled', 'updated_at' => now()]);

                return;
            }
            // Commit the claim before contacting the push service. A deadlock retry
            // or worker restart must never repeat an external notification.
            DB::table('catch_reminders')->where('id', $r->id)->update(['status' => 'sending', 'updated_at' => now()]);

            return ['subscription' => $sub, 'payload' => [
                'title' => 'Your Fresh Catch is ready 🍣', 'body' => 'Ten questions, one small step. Pick up where you left off.',
                'url' => '/fresh-catch?reminder='.$r->id, 'tag' => 'fresh-catch-'.$r->day,
            ]];
        }, 3);
        if (! $delivery) {
            return;
        }
        $sub = $delivery['subscription'];
        try {
            $status = $transport->send(json_decode(Crypt::decryptString($sub->subscription), true), $delivery['payload']);
        } catch (\Throwable $e) {
            report($e);
            $status = 'failed';
        }
        if ($status === 'expired') {
            DB::table('push_subscriptions')->where('id', $sub->id)->delete();
        }
        DB::table('catch_reminders')->where('id', $id)->where('status', 'sending')->update(['status' => $status, 'sent_at' => $status === 'sent' ? now() : null, 'updated_at' => now()]);
    }
}
