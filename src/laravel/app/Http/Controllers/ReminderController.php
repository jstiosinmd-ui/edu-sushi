<?php

namespace App\Http\Controllers;

use App\Models\Attempt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReminderController extends Controller
{
    public function settings(Request $r)
    {
        $d = $r->validate(['timezone' => 'required|timezone', 'reminder_time' => 'required|date_format:H:i', 'reminder_enabled' => 'required|boolean']);
        $u = $r->user();
        if ($d['timezone'] !== $u->timezone) {
            if ($u->timezone_changed_at && $u->timezone_changed_at->gt(now()->subDays(7))) {
                throw ValidationException::withMessages(['timezone' => 'You can change your study timezone once every seven days.']);
            }
            $d['timezone_changed_at'] = now();
        }
        $u->update($d);

        return back()->with('message', 'Reminder preferences saved.');
    }

    public function subscribe(Request $r)
    {
        abort_unless(config('sushi.push.public_key') && config('sushi.push.private_key'), 503, 'Push setup is not enabled on this server yet.');
        $d = $r->validate(['endpoint' => 'required|url:https|max:2048', 'keys.p256dh' => 'required|string|max:200', 'keys.auth' => 'required|string|max:100']);
        $url = parse_url($d['endpoint']);
        $host = strtolower($url['host'] ?? '');
        $allowed = $host === 'fcm.googleapis.com' || $host === 'updates.push.services.mozilla.com' || $host === 'web.push.apple.com' || str_ends_with($host, '.push.apple.com') || str_ends_with($host, '.notify.windows.com');
        $decode = fn ($s) => base64_decode(strtr($s, '-_', '+/'), true);
        if (! $allowed || isset($url['user']) || isset($url['pass']) || (isset($url['port']) && $url['port'] !== 443) || strlen($decode($d['keys']['p256dh']) ?: '') !== 65 || strlen($decode($d['keys']['auth']) ?: '') !== 16) {
            throw ValidationException::withMessages(['endpoint' => 'This browser push subscription is not supported or is invalid.']);
        }
        DB::table('push_subscriptions')->updateOrInsert(['endpoint_hash' => hash('sha256', $d['endpoint'])], ['user_id' => $r->user()->id, 'subscription' => Crypt::encryptString(json_encode($d)), 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['saved' => true]);
    }

    public function unsubscribe(Request $r)
    {
        $r->validate(['endpoint' => 'required|string|max:2048']);
        DB::table('push_subscriptions')->where('user_id', $r->user()->id)->where('endpoint_hash', hash('sha256', $r->input('endpoint')))->delete();

        return response()->json(['saved' => true]);
    }

    public function dismiss(Request $r)
    {
        $d = $r->validate(['action' => 'required|in:snooze,skip']);
        $u = $r->user();
        $day = now($u->timezone)->toDateString();
        if ($d['action'] === 'skip') {
            $u->update(['reminder_dismissed_date' => $day, 'reminder_snoozed_until' => null]);
        } else {
            $u->update(['reminder_snoozed_until' => now()->addHour()]);
            DB::table('catch_reminders')->where('user_id', $u->id)->where('day', $day)->update(['status' => 'snoozed', 'updated_at' => now()]);
        }

        return back()->with('message', $d['action'] === 'skip' ? 'Reminder dismissed for today. Daily Catch rules still apply.' : 'We will remind you after one hour if your catch is unfinished.');
    }

    public function fresh(Request $r)
    {
        if ($r->integer('reminder')) {
            DB::table('catch_reminders')->where('id', $r->integer('reminder'))->where('user_id', $r->user()->id)->whereNull('clicked_at')->update(['clicked_at' => now()]);
        }
        $attempt = Attempt::where('user_id', $r->user()->id)->where('mode', 'fresh')->where('status', 'active')->latest('id')->first();

        return redirect($attempt ? '/attempts/'.$attempt->id : '/practice?mode=fresh');
    }
}
