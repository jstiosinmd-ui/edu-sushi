<?php

namespace App\Services;

use App\Models\Flashcard;
use App\Models\Progress;
use App\Models\Question;

class Achievements
{
    public static function at(int $count): array
    {
        $level = intdiv($count, 200);
        $name = fn ($n) => ['Bronze', 'Silver', 'Gold'][$n] ?? ('Sakura '.($n - 2));

        return ['count' => $count, 'level' => $level, 'frame' => ['bronze', 'silver', 'gold'][$level] ?? 'sakura', 'name' => $name($level), 'next' => $name($level + 1), 'threshold' => ($level + 1) * 200, 'remaining' => 200 - ($count % 200), 'percent' => ($count % 200) / 2,
            'tiers' => array_map(fn ($n) => ['name' => $name($n), 'at' => $n * 200, 'frame' => ['bronze', 'silver', 'gold'][$n] ?? 'sakura'], range(0, max(12, $level + 2)))];
    }

    public static function for(int $userId): array
    {
        $ids = Progress::where('user_id', $userId)->whereNotNull('completed_at')->pluck('question_id');
        $ids = $ids->merge(Flashcard::where('user_id', $userId)->whereNotNull('confidence')->pluck('question_id'));

        $hashes = Question::whereIn('id', $ids->unique())->pluck('stem_hash', 'id');

        return self::at($ids->unique()->map(fn ($id) => $hashes[$id] ?? $id)->unique()->count());
    }
}
