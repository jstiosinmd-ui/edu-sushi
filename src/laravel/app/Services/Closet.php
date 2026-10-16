<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class Closet
{
    public static function all(User $user): array
    {
        $earned = DB::table('earned_badges')->where('user_id', $user->id)->pluck('badge')->all();
        $rank = Achievements::for($user->id);
        $frames = array_column(array_filter($rank['frames'], fn ($f) => $f['unlocked']), 'id');
        $frame = in_array($user->avatar_frame, $frames, true) ? $user->avatar_frame : $rank['frame'];

        return array_map(fn ($a) => [...$a, 'frame' => $frame,
            'unlocked' => isset($a['threshold']) ? $rank['count'] >= $a['threshold'] : (! $a['badge'] || in_array($a['badge'], $earned, true))],
            json_decode(file_get_contents(resource_path('characters.json')), true));
    }

    public static function profile(User $user): array
    {
        $all = self::all($user);
        $avatar = collect($all)->firstWhere('id', $user->avatar) ?? $all[0];
        if (! $avatar['unlocked']) {
            $avatar = $all[0];
        }
        $earned = DB::table('earned_badges')->where('user_id', $user->id)->pluck('badge');

        return ['id' => $user->id, 'display_name' => $user->display_name ?: $user->username, 'bio' => $user->bio,
            'avatar' => $avatar, 'pins' => array_values(array_intersect($user->pinned_badges ?? [], $earned->all()))];
    }
}
