<?php

namespace App\Http\Controllers;

use App\Services\Closet;
use App\Services\Passport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ProfileController extends Controller
{
    public function index(Request $r)
    {
        $badges = app(Passport::class)->for($r->user());

        return Inertia::render('Profile', ['profile' => Closet::profile($r->user()), 'closet' => Closet::all($r->user()), 'badges' => $badges]);
    }

    public function settings(Request $r)
    {
        return Inertia::render('Preferences', ['preferences' => $r->user()->only('timezone', 'reminder_time', 'reminder_enabled'), 'pushKey' => config('sushi.push.public_key')]);
    }

    public function equip(Request $r)
    {
        $r->validate(['avatar' => 'required|string|max:60']);
        $this->validateAvatar($r);
        $r->user()->update(['avatar' => $r->input('avatar')]);

        return back()->with('message', 'Your character is equipped.');
    }

    private function validateAvatar(Request $r): void
    {
        if (! in_array($r->input('avatar'), collect(Closet::all($r->user()))->where('unlocked', true)->pluck('id')->all(), true)) {
            throw ValidationException::withMessages(['avatar' => 'Earn the achievement to unlock this character.']);
        }
    }

    public function update(Request $r)
    {
        $d = $r->validate(['display_name' => 'required|string|max:50', 'bio' => 'nullable|string|max:240',
            'avatar' => 'required|string|max:60', 'pins' => 'array|max:3', 'pins.*' => 'string|distinct|max:80', 'photo' => 'prohibited', 'remove_photo' => 'prohibited']);
        $this->validateAvatar($r);
        $earned = DB::table('earned_badges')->where('user_id', $r->user()->id)->pluck('badge')->all();
        if (array_diff($d['pins'] ?? [], $earned)) {
            throw ValidationException::withMessages(['pins' => 'Only earned achievements can be pinned.']);
        }
        $r->user()->update(['display_name' => trim($d['display_name']), 'bio' => $d['bio'] ?? null, 'avatar' => $d['avatar'], 'pinned_badges' => $d['pins'] ?? []]);

        return back()->with('message', 'Your profile is saved.');
    }
}
