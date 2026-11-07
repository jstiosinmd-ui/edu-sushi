<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class SpecialSets
{
    public const SLUGS = ['pathology-2027', 'legal-medicine-2027', 'biochemistry-2027', 'physiology-2027', 'anatomy-2027'];

    public function load(string $slug): array
    {
        abort_unless(in_array($slug, self::SLUGS, true), 404);
        $path = database_path('data/special-sets/'.$slug.'.json');
        $set = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $set['hash'] = hash_file('sha256', $path);

        return $set;
    }

    public function answers(int $userId, array $set): array
    {
        $row = DB::table('special_set_progress')->where('user_id', $userId)->where('slug', $set['slug'])->where('content_hash', $set['hash'])->first();

        return $row ? json_decode($row->answers, true, 512, JSON_THROW_ON_ERROR) : [];
    }

    public function summary(array $set, array $answers): array
    {
        $scored = $correct = $reviewed = 0;
        foreach ($set['items'] as $q) {
            if (! array_key_exists($q['number'], $answers)) {
                continue;
            }
            if ($q['review_only']) {
                $reviewed++;
            } else {
                $scored++;
                $correct += (int) ($answers[$q['number']]['answer'] === $q['key']);
            }
        }

        return ['completed' => $scored + $reviewed, 'scored' => $scored, 'correct' => $correct, 'reviewed' => $reviewed,
            'total' => count($set['items']), 'scorable' => count(array_filter($set['items'], fn ($q) => ! $q['review_only']))];
    }

    public function feedback(array $q, array $answer): array
    {
        return ['answer' => $answer['answer'], 'key' => $q['key'], 'correct' => $q['review_only'] ? null : $answer['answer'] === $q['key'],
            'rationale' => $q['rationale'], 'references' => $q['references'], 'review_only' => $q['review_only']];
    }
}
