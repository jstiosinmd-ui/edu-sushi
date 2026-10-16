<?php

namespace App\Services;

use App\Models\Flashcard;
use App\Models\Progress;
use App\Models\Question;
use Illuminate\Support\Facades\DB;

class Achievements
{
    public const NAMES = [
        'Rice Rookie', 'Maki Explorer', 'Nigiri Navigator', 'Temaki Trailblazer',
        'Wasabi Warrior', 'Mochi Maestro', 'Sakura Sensei', 'Sashimi Scholar',
        'Bento Virtuoso', 'Nori Grandmaster', 'Sushi Shogun', 'Golden Chopsticks',
        'Tamago Tactician', 'Ebi Envoy', 'Inari Illusionist', 'Kappa Captain',
        'Unagi Sage', 'Ikura Illuminator', 'Gunkan Guardian', 'Chirashi Champion',
        'Dragon Roll Regent', 'Toro Trailmaster', 'Temari Treasurekeeper',
        'Rainbow Roll Royalty', 'Sushi Starlight', 'Omakase Legend',
        'Yuzu Moonkeeper', 'Onigiri Oracle', 'Saba Stargazer', 'Futomaki Phoenix', 'Sushi Aurora',
    ];

    public static function frames(int $count): array
    {
        return array_map(fn ($id, $at) => ['id' => $id, 'name' => ucfirst($id), 'at' => $at, 'unlocked' => $count >= $at],
            ['bronze', 'silver', 'gold', 'sakura'], [0, 200, 400, 600]);
    }

    public static function at(int $count): array
    {
        $level = intdiv($count, 200);
        $name = fn ($n) => self::NAMES[$n] ?? self::futureName($n - count(self::NAMES));

        return ['count' => $count, 'level' => $level, 'frame' => ['bronze', 'silver', 'gold'][$level] ?? 'sakura', 'frames' => self::frames($count), 'name' => $name($level), 'next' => $name($level + 1), 'threshold' => ($level + 1) * 200, 'remaining' => 200 - ($count % 200), 'percent' => ($count % 200) / 2,
            'tiers' => array_map(fn ($n) => ['name' => $name($n), 'at' => $n * 200, 'image' => self::portrait($n)], range(0, max(count(self::NAMES) - 1, $level + 1)))];
    }

    private static function futureName(int $n): string
    {
        // Stable names independent of the growing bank size; earned tiers never move.
        $foods = ['Hamachi', 'Hotate', 'Ume', 'Tobiko', 'Shiso', 'Akami', 'Anago', 'Kazunoko', 'Engawa', 'Uni', 'Hokkigai', 'Kanpyo'];
        $roles = ['Dreamweaver', 'Wayfinder', 'Luminary', 'Starguard', 'Moonrider', 'Sunseeker', 'Cloudkeeper', 'Starfarer'];
        $name = $foods[$n % count($foods)].' '.$roles[intdiv($n, count($foods)) % count($roles)];
        $cycle = intdiv($n, count($foods) * count($roles));

        return $cycle ? str_repeat('Celestial ', $cycle).$name : $name;
    }

    public static function portrait(int $level): ?string
    {
        $ids = ['yuzu-moonkeeper', 'onigiri-oracle', 'saba-stargazer', 'futomaki-phoenix', 'sushi-aurora'];

        return isset($ids[$level - 26]) ? '/art/portraits/'.$ids[$level - 26].'-v2.9.png' : null;
    }

    public static function for(int $userId): array
    {
        $ids = Progress::where('user_id', $userId)->whereNotNull('completed_at')->pluck('question_id');
        $ids = $ids->merge(Flashcard::where('user_id', $userId)->whereNotNull('confidence')->pluck('question_id'));
        $ids = $ids->merge(DB::table('study_events')->where('user_id', $userId)->where('mode', 'memory')->pluck('question_id'));

        $hashes = Question::whereIn('id', $ids->unique())->pluck('stem_hash', 'id');

        return self::at($ids->unique()->map(fn ($id) => $hashes[$id] ?? $id)->unique()->count());
    }
}
