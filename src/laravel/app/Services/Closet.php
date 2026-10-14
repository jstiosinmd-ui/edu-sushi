<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class Closet
{
    public static function all(User $user): array
    {
        $earned = DB::table('earned_badges')->where('user_id', $user->id)->pluck('badge')->all();
        $base = [
            ['rice', 'Rice Rookie', '/art/rice-rookie.png', null, null, null],
            ['catch', 'Captain Catch', '/art/20261013_EDU_SushiCharacterTemaki_v1.1.png', 'fresh_first', 'fresh_perfect', 'fresh_50'],
            ['mentor', 'Maki Mentor', '/art/20261013_EDU_SushiCharacterMaki_v1.1.png', 'tutor_100', 'tutor_comeback', 'tutor_four'],
            ['wasabi', 'Wasabi Warrior', '/art/20261013_EDU_SushiCharacterWasabi_v1.1.png', 'rush_cool', 'rush_triple', 'rush_four'],
            ['memory', 'Memory Maki', '/art/20261013_EDU_SushiMemoMaki_v2.0.png', 'memory_ready', 'memory_rescue', 'memory_golden'],
            ['bento', 'Bento Chef', '/art/20261013_EDU_SushiCharacterChef_v1.1.png', 'bento_chef', 'bento_serve', 'bento_prep'],
            ['nori', 'Nori Librarian', '/art/nori-librarian.png', 'library_notes', 'library_sampler', 'library_100'],
            ['im', 'Miso Medic', '/art/20261013_EDU_SushiSubjectIM_v1.2.png', 'subject_im_50', 'subject_im_100', 'subject_im_200'],
            ['pedia', 'Pip the Pedia Roll', '/art/20261013_EDU_SushiSubjectPediatrics_v1.2.png', 'subject_pedia_50', 'subject_pedia_100', 'subject_pedia_200'],
            ['surgery', 'Suture Salmon', '/art/20261013_EDU_SushiSubjectSurgery_v1.2.png', 'subject_surgery_50', 'subject_surgery_100', 'subject_surgery_200'],
            ['ob', 'Nori Nurture', '/art/20261013_EDU_SushiSubjectOBGYN_v1.2.png', 'subject_ob_50', 'subject_ob_100', 'subject_ob_200'],
        ];
        $items = [];
        foreach ($base as [$id, $name, $image, $badge, $sakura, $gold]) {
            foreach (['base' => $badge, 'sakura' => $sakura, 'gold' => $gold] as $frame => $requirement) {
                if ($frame !== 'base' && ! $requirement) {
                    continue;
                }
                $items[] = ['id' => $id.($frame === 'base' ? '' : '-'.$frame), 'name' => $name.($frame === 'base' ? '' : ($frame === 'gold' ? ' · Golden Omakase' : ' · Sakura')),
                    'image' => $image, 'frame' => $frame, 'badge' => $requirement, 'unlocked' => ! $requirement || in_array($requirement, $earned, true)];
            }
        }
        $items[] = ['id' => 'rice-wasabi', 'name' => 'Rice Rookie · Wasabi', 'image' => '/art/rice-rookie.png', 'frame' => 'wasabi', 'badge' => 'streak_7', 'unlocked' => in_array('streak_7', $earned, true)];

        return $items;
    }

    public static function profile(User $user): array
    {
        $avatar = collect(self::all($user))->firstWhere('id', $user->avatar) ?? self::all($user)[0];
        if (! $avatar['unlocked']) {
            $avatar = self::all($user)[0];
        }
        if ($user->avatar === 'photo' && $user->photo_path) {
            $avatar = ['id' => 'photo', 'name' => 'Profile photo', 'image' => '/profiles/'.$user->id.'/photo?v='.md5($user->photo_path), 'frame' => 'base'];
        }
        $earned = DB::table('earned_badges')->where('user_id', $user->id)->pluck('badge');

        return ['id' => $user->id, 'display_name' => $user->display_name ?: $user->username, 'bio' => $user->bio, 'avatar' => $avatar,
            'pins' => array_values(array_intersect($user->pinned_badges ?? [], $earned->all()))];
    }
}
