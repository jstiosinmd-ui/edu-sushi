<?php

namespace App\Services;

use App\Models\Attempt;
use App\Models\Flashcard;
use App\Models\Progress;
use App\Models\Question;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class Analytics
{
    public function for(int $userId, array $filters = []): array
    {
        $attempts = Attempt::where('user_id', $userId);
        if (! empty($filters['mode'])) {
            $attempts->where('mode', $filters['mode']);
        }
        $responses = DB::table('attempt_items as i')->join('attempts as a', 'a.id', '=', 'i.attempt_id')
            ->where('a.user_id', $userId)->whereNotNull('i.graded_at')->whereNotNull('i.answer')->select('i.*', 'a.mode')->orderBy('i.graded_at')->orderBy('i.id');
        // First-ever response is determined before filtering dates or modes.
        $all = $responses->get()->map(function ($r) {
            $r->q = json_decode($r->snapshot, true);

            return $r;
        });
        $unknown = Progress::where('user_id', $userId)->where('history_unknown', true)->pluck('question_id')->all();
        $first = $all->reject(fn ($r) => in_array($r->question_id, $unknown, true))->unique('question_id');
        $keep = function ($r) use ($filters) {
            if (! empty($filters['subject']) && $r->q['subject'] !== $filters['subject']) {
                return false;
            }
            if (! empty($filters['topic']) && $r->q['topic'] !== $filters['topic']) {
                return false;
            }
            if (! empty($filters['difficulty']) && $r->q['tier'] !== $filters['difficulty']) {
                return false;
            }
            if (! empty($filters['mode']) && $r->mode !== $filters['mode']) {
                return false;
            }
            $date = Carbon::parse($r->graded_at, 'UTC')->timezone('Asia/Manila')->toDateString();

            return (empty($filters['from']) || $date >= $filters['from']) && (empty($filters['to']) || $date <= $filters['to']);
        };
        // Include mode without selecting ambiguous joined id/timestamp columns.
        $modes = Attempt::where('user_id', $userId)->pluck('mode', 'id');
        foreach ($all as $r) {
            $r->mode = $modes[$r->attempt_id];
        }
        $filtered = $all->filter($keep);
        $firstFiltered = $first->filter($keep);
        $groups = $filtered->groupBy(fn ($r) => $r->q['subject'].' / '.$r->q['topic'])->map(fn ($rows, $label) => [
            'label' => $label, 'answered' => $rows->count(), 'correct' => $rows->filter(fn ($r) => (bool) $r->correct)->count(),
        ])->values();
        $p = Progress::where('user_id', $userId)->whereNotNull('completed_at')->get();
        $activeIds = Question::where('active', true)->pluck('id');
        $activeCompleted = $p->whereIn('question_id', $activeIds)->count();
        $due = Flashcard::where('user_id', $userId)->whereNotNull('confidence')->where('confidence', '<=', 2)->count();

        return ['completed' => $p->count(), 'active_completed' => $activeCompleted, 'bank_total' => $activeIds->count(),
            'legacy' => $p->where('legacy', true)->count(), 'first_correct' => $firstFiltered->filter(fn ($r) => (bool) $r->correct)->count(), 'first_total' => $firstFiltered->count(),
            'graded_correct' => $filtered->filter(fn ($r) => (bool) $r->correct)->count(), 'graded_total' => $filtered->count(),
            'latest_correct' => $p->where('latest_correct', true)->count(), 'latest_total' => $p->count(),
            'groups' => $groups, 'flashcards_due' => $due, 'flashcard_reviews' => DB::table('flashcard_reviews')->where('user_id', $userId)->count(),
            'attempts' => $attempts->withCount('items')->latest()->limit(50)->get(['id', 'mode', 'status', 'score', 'active_seconds', 'created_at', 'finished_at']),
            'coverage' => collect(Catalog::SUBJECTS)->map(fn ($s) => ['subject' => $s, 'completed' => $p->whereIn('question_id', Question::where('subject', $s)->where('active', true)->pluck('id'))->count(), 'total' => Question::where('subject', $s)->where('active', true)->count()]),
            'review' => Question::whereIn('id', $p->where('latest_correct', false)->pluck('question_id'))->where('active', true)->limit(30)->get(['id', 'subject', 'topic']),
        ];
    }
}
