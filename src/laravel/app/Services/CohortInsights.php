<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CohortInsights
{
    public function learners(?string $cohort = null): Collection
    {
        $q = User::where('role', 'student')->where('disabled', false)->where('invitation_pending', false)->whereNull('archived_at');
        if ($cohort === '__unassigned__') {
            $q->where(fn ($q) => $q->whereNull('cohort')->orWhere('cohort', ''));
        } elseif ($cohort !== null && $cohort !== '') {
            $q->where('cohort', $cohort);
        }

        return $q->orderBy('username')->get();
    }

    public function overview(Collection $users): array
    {
        $questions = collect(app(Catalog::class)->candidates())->unique(fn ($q) => Catalog::questionHash($q));
        $byHash = $questions->keyBy(fn ($q) => Catalog::questionHash($q));
        $events = DB::table('study_events')->whereIn('user_id', $users->pluck('id'))->where('mode', '!=', 'assigned')
            ->orderBy('occurred_at')->orderBy('id')->get();
        $firstByUser = app(StudyGuide::class)->knownFirst($events)->groupBy('user_id');
        $events = $events->groupBy('user_id');
        $imports = DB::table('progress')->join('questions', 'questions.id', '=', 'progress.question_id')
            ->whereIn('user_id', $users->pluck('id'))->where('legacy', true)->whereNotNull('completed_at')
            ->get(['user_id', 'stem_hash'])->groupBy('user_id');
        $rows = $users->map(function ($u) use ($questions, $byHash, $events, $firstByUser, $imports) {
            $first = ($firstByUser[$u->id] ?? collect())->filter(fn ($e) => $byHash->has($e->question_hash));
            $covered = ($events[$u->id] ?? collect())->pluck('question_hash')->merge(($imports[$u->id] ?? collect())->pluck('stem_hash'))->unique()->flip();
            $subjects = array_map(function ($s) use ($first, $questions, $byHash, $covered) {
                $attempted = $first->filter(fn ($e) => $byHash[$e->question_hash]['subject'] === $s);
                $topics = $attempted->groupBy(fn ($e) => $byHash[$e->question_hash]['topic'])
                    ->map(fn ($events, $topic) => ['topic' => $topic, 'total' => $events->count(), 'correct' => $events->where('correct', true)->count(),
                        'available' => $questions->where('subject', $s)->where('topic', $topic)->count()])
                    ->filter(fn ($t) => $t['correct'] < $t['total'])->sortBy(fn ($t) => $t['correct'] / $t['total'])->take(3)->values()->all();

                return ['subject' => $s, 'available' => $questions->where('subject', $s)->count(), 'attempted' => $attempted->count(),
                    'covered' => $questions->where('subject', $s)->filter(fn ($q) => $covered->has(Catalog::questionHash($q)))->count(),
                    'correct' => $attempted->where('correct', true)->count(), 'topics' => $topics];
            }, Catalog::SUBJECTS);
            $right = $first->where('correct', true)->count();
            $support = [];
            if (! $u->last_active_at || $u->last_active_at->lt(now()->subDays(7))) {
                $support[] = 'No recorded study activity in the past 7 days';
            }
            if ($first->count() >= 5 && $right / $first->count() < .6) {
                $support[] = 'Below 60% on at least 5 first practice responses';
            }

            return ['id' => $u->id, 'username' => $u->username, 'cohort' => $u->cohort, 'last_active_at' => $u->last_active_at?->toIso8601String(),
                'attempted' => $questions->filter(fn ($q) => $covered->has(Catalog::questionHash($q)))->count(),
                'first_total' => $first->count(), 'correct' => $right, 'subjects' => $subjects, 'support' => $support];
        })->values();

        return ['rows' => $rows->all(), 'learners' => $users->count(), 'available' => $questions->count(),
            'active_seven_days' => $users->filter(fn ($u) => $u->last_active_at?->gte(now()->subDays(7)))->count(),
            'first_total' => $rows->sum('first_total'), 'first_correct' => $rows->sum('correct')];
    }

    public function items(Collection $users): Collection
    {
        $attempts = DB::table('attempt_items as i')->join('attempts as a', 'a.id', '=', 'i.attempt_id')
            ->whereIn('a.user_id', $users->pluck('id'))->where('a.mode', '!=', 'assigned')->whereNotNull('i.graded_at')->whereNotNull('i.answer')
            ->get(['a.user_id', 'i.question_id', 'i.snapshot', 'i.answer', 'i.correct', 'i.graded_at as answered_at', 'i.id']);
        $memory = DB::table('flashcard_reviews as r')->join('flashcard_deck_items as i', 'i.id', '=', 'r.flashcard_deck_item_id')
            ->leftJoin('study_events as e', 'e.source', '=', DB::raw("CONCAT('memory:', r.review_token)"))
            ->whereIn('r.user_id', $users->pluck('id'))->whereNotNull('r.answer')->whereNotNull('r.correct')
            ->select(['r.user_id', 'r.question_id', 'i.snapshot', 'r.answer', 'r.correct', 'r.id'])
            ->selectRaw('COALESCE(e.occurred_at, r.created_at) as answered_at')->get();
        $first = $attempts->merge($memory)->map(function ($r) {
            $q = json_decode($r->snapshot, true);
            $r->content = $q;
            // Keep changed options and keys in distinct version groups.
            $r->version_key = $r->question_id.':'.($q['version'] ?? hash('sha256', $r->snapshot));

            return $r;
        })->sortBy(fn ($r) => $r->answered_at.':'.str_pad((string) $r->id, 20, '0', STR_PAD_LEFT))
            ->unique(fn ($r) => $r->user_id.':'.$r->version_key);

        return $first->groupBy('version_key')->map(function ($rows) {
            $q = $rows->first()->content;
            $n = $rows->count();
            $correct = $rows->where('correct', true)->count();

            return ['id' => $q['id'], 'version' => $q['version'] ?? 'legacy', 'subject' => $q['subject'], 'topic' => $q['topic'],
                'n' => $n, 'correct' => $correct, 'percent' => $n >= 20 ? round($correct / $n * 100, 1) : null,
                'status' => $n >= 20 ? 'Descriptive only' : 'Insufficient data (20 learners needed)',
                'options' => array_map(fn ($letter, $text) => ['letter' => $letter, 'text' => $text, 'count' => $rows->where('answer', $letter)->count(), 'key' => $q['key'] === $letter], ['A', 'B', 'C', 'D'], $q['options'])];
        })->sortByDesc('n')->values();
    }
}
