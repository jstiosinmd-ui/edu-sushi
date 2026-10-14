<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Closet;
use App\Services\Passport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ProfileController extends Controller
{
    public function index(Request $r)
    {
        return Inertia::render('Profile', ['profile' => Closet::profile($r->user()), 'closet' => Closet::all($r->user()),
            'hasPhoto' => (bool) $r->user()->photo_path, 'badges' => app(Passport::class)->for($r->user()),
            'photoUrl' => $r->user()->photo_path ? '/profiles/'.$r->user()->id.'/photo?v='.md5($r->user()->photo_path) : null,
            'preferences' => $r->user()->only('timezone', 'reminder_time', 'reminder_enabled'), 'pushKey' => config('sushi.push.public_key')]);
    }

    public function update(Request $r)
    {
        $d = $r->validate(['display_name' => 'required|string|max:50', 'bio' => 'nullable|string|max:240',
            'avatar' => 'required|string|max:60', 'pins' => 'array|max:3', 'pins.*' => 'string|distinct|max:80',
            'photo' => 'nullable|file|mimes:jpg,jpeg,png,webp|max:4096|dimensions:max_width=6000,max_height=6000',
            'remove_photo' => 'boolean']);
        $u = $r->user();
        $available = collect(Closet::all($u))->where('unlocked', true)->pluck('id')->all();
        if ($u->photo_path || $r->hasFile('photo')) {
            $available[] = 'photo';
        }
        if (! in_array($d['avatar'], $available, true)) {
            throw ValidationException::withMessages(['avatar' => 'Earn the achievement to unlock this portrait.']);
        }
        $earned = DB::table('earned_badges')->where('user_id', $u->id)->pluck('badge')->all();
        if (array_diff($d['pins'] ?? [], $earned)) {
            throw ValidationException::withMessages(['pins' => 'Only earned achievements can be pinned.']);
        }
        $updates = ['display_name' => trim($d['display_name']), 'bio' => $d['bio'] ?? null, 'avatar' => $d['avatar'], 'pinned_badges' => $d['pins'] ?? []];
        if ($r->boolean('remove_photo')) {
            $updates['photo_path'] = null;
            if ($updates['avatar'] === 'photo') {
                $updates['avatar'] = 'rice';
            }
        }
        if ($r->hasFile('photo')) {
            $source = @imagecreatefromstring(file_get_contents($r->file('photo')->getRealPath()));
            if (! $source) {
                throw ValidationException::withMessages(['photo' => 'Choose a readable JPEG, PNG, or WebP image.']);
            }
            $size = min(imagesx($source), imagesy($source));
            $target = imagecreatetruecolor(512, 512);
            imagefill($target, 0, 0, imagecolorallocate($target, 255, 248, 243));
            imagecopyresampled($target, $source, 0, 0, (int) ((imagesx($source) - $size) / 2), (int) ((imagesy($source) - $size) / 2), 512, 512, $size, $size);
            ob_start();
            imagejpeg($target, null, 88);
            $bytes = ob_get_clean();
            $path = 'profiles/'.$u->id.'/'.Str::uuid().'.jpg';
            abort_unless(Storage::disk('local')->put($path, $bytes), 500, 'Could not save the photo.');
            $updates['photo_path'] = $path;
        }
        $u->update($updates);

        return back()->with('message', 'Your profile is saved.');
    }

    public function photo(User $user)
    {
        abort_if($user->disabled || ! $user->photo_path, 404);

        return Storage::disk('local')->response($user->photo_path, null, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=300', 'X-Content-Type-Options' => 'nosniff']);
    }
}
