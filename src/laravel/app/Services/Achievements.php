<?php

namespace App\Services;

use App\Models\Flashcard;
use App\Models\Progress;
use App\Models\Question;

class Achievements
{
    public const NAMES = [
        'Rice Rookie', 'Maki Explorer', 'Nigiri Navigator', 'Temaki Trailblazer',
        'Wasabi Warrior', 'Omakase Legend', 'Sakura Sensei', 'Sashimi Scholar',
        'Bento Virtuoso', 'Nori Grandmaster', 'Sushi Shogun', 'Golden Chopsticks',
        'Tamago Tactician', 'Ebi Envoy', 'Inari Illusionist', 'Kappa Captain',
        'Unagi Sage', 'Ikura Illuminator', 'Gunkan Guardian', 'Chirashi Champion',
        'Dragon Roll Regent', 'Toro Trailmaster', 'Temari Treasurekeeper',
        'Rainbow Roll Royalty', 'Sushi Starlight', 'Omakase Master',
    ];

    public static function frames(int $count): array
    {
        return array_map(fn ($id, $at) => ['id' => $id, 'name' => ucfirst($id), 'at' => $at, 'unlocked' => $count >= $at],
            ['bronze', 'silver', 'gold', 'sakura'], [0, 200, 400, 600]);
    }

    public static function at(int $count): array
    {
        $level = intdiv($count, 200);
        $name = fn ($n) => self::NAMES[$n] ?? ('Omakase Master '.($n - count(self::NAMES) + 2));

        return ['count' => $count, 'level' => $level, 'frame' => ['bronze', 'silver', 'gold'][$level] ?? 'sakura', 'frames' => self::frames($count), 'name' => $name($level), 'next' => $name($level + 1), 'threshold' => ($level + 1) * 200, 'remaining' => 200 - ($count % 200), 'percent' => ($count % 200) / 2,
            'tiers' => array_map(fn ($n) => ['name' => $name($n), 'at' => $n * 200], range(0, max(count(self::NAMES) - 1, $level + 1)))];
    }

    public static function for(int $userId): array
    {
        $ids = Progress::where('user_id', $userId)->whereNotNull('completed_at')->pluck('question_id');
        $ids = $ids->merge(Flashcard::where('user_id', $userId)->whereNotNull('confidence')->pluck('question_id'));

        $hashes = Question::whereIn('id', $ids->unique())->pluck('stem_hash', 'id');

        return self::at($ids->unique()->map(fn ($id) => $hashes[$id] ?? $id)->unique()->count());
    }
}
