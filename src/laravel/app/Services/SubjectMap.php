<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class SubjectMap
{
    public function for(int $userId): array
    {
        $items = collect(app(Catalog::class)->items())->unique(fn ($q) => Catalog::stemHash($q['stem']));
        $events = app(StudyGuide::class)->events($userId);
        $first = app(StudyGuide::class)->knownFirst($events)->keyBy('question_hash');
        $covered = $events->keyBy('question_hash');
        $imported = DB::table('progress')->join('questions', 'questions.id', '=', 'progress.question_id')->where('user_id', $userId)->where('legacy', true)->whereNotNull('completed_at')->pluck('stem_hash')->flip();
        $correct = $events->where('correct', true)->keyBy('question_hash');
        $ratings = DB::table('flashcards')->where('user_id', $userId)->pluck('confidence', 'question_id');
        $earned = DB::table('earned_badges')->where('user_id', $userId)->pluck('badge')->all();

        return array_map(function ($subject) use ($items, $first, $covered, $imported, $correct, $ratings, $earned) {
            $pool = $items->where('subject', $subject);
            $attempted = $pool->filter(fn ($q) => $covered->has(Catalog::stemHash($q['stem'])) || $imported->has(Catalog::stemHash($q['stem'])));
            $known = $pool->filter(fn ($q) => $first->has(Catalog::stemHash($q['stem'])));
            $right = $known->filter(fn ($q) => $first->get(Catalog::stemHash($q['stem']))->correct)->count();
            $everRight = $pool->filter(fn ($q) => $correct->has(Catalog::stemHash($q['stem'])))->count();
            $topics = $known->groupBy('topic')->map(fn ($qs, $topic) => ['topic' => $topic, 'total' => $qs->count(),
                'correct' => $qs->filter(fn ($q) => $first->get(Catalog::stemHash($q['stem']))->correct)->count()])
                ->filter(fn ($t) => $t['correct'] < $t['total'])->sortBy(fn ($t) => $t['correct'] / $t['total'])->take(3)->values()->all();
            $badges = collect(Passport::definitions())->where('group', $subject)->sortBy('target');
            $unlock = $badges->first();
            $next = $badges->first(fn ($b) => ! in_array($b['id'], $earned, true) && $everRight < $b['target'] && $b['target'] <= $pool->count());

            return ['subject' => $subject, 'total' => $pool->count(), 'attempted' => $attempted->count(),
                'remaining' => $pool->count() - $attempted->count(), 'first_correct' => $right, 'first_total' => $known->count(),
                'confident' => $pool->filter(fn ($q) => ($ratings[$q['id']] ?? null) === 5)->count(),
                'rated' => $pool->filter(fn ($q) => isset($ratings[$q['id']]))->count(), 'distinct_correct' => $everRight,
                'avatar_unlocked' => $unlock && (in_array($unlock['id'], $earned, true) || $everRight >= $unlock['target']),
                'next' => $next ? ['name' => $next['name'], 'target' => $next['target'], 'progress' => min($next['target'], $everRight)] : null,
                'topics' => $topics];
        }, Catalog::SUBJECTS);
    }
}
