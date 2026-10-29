<?php

namespace App\Services;

use App\Models\Progress;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class ProgressScope
{
    public static function dates(array $filters): array
    {
        if (in_array((string) ($filters['period'] ?? ''), ['1', '7', '30'], true)) {
            $today = now('Asia/Manila');
            $filters['to'] = $today->toDateString();
            $filters['from'] = $today->copy()->subDays((int) $filters['period'] - 1)->toDateString();
        }

        return $filters;
    }

    public function for(int $userId, array $filters = [], ?Collection $responses = null): array
    {
        $filters = self::dates($filters);
        $candidateFilters = ['difficulty' => $filters['difficulty'] ?? 'All'];
        if (empty($candidateFilters['difficulty'])) {
            $candidateFilters['difficulty'] = 'All';
        }
        if (! empty($filters['subject'])) {
            $candidateFilters['subjects'] = [$filters['subject']];
        }
        $questions = collect(app(Catalog::class)->candidates($candidateFilters))->filter(fn ($q) => empty($filters['topic'])
            || $q['topic'] === $filters['topic'] || Taxonomy::matches($q, $filters['topic']))->keyBy('id');
        $all = ($responses ?? app(StudyHistory::class)->responses($userId))
            ->filter(fn ($r) => $questions->has($r->question_id) && ($r->mode !== 'assigned' || $r->feedback_released));
        $inScope = function ($r) use ($filters) {
            $day = Carbon::parse($r->graded_at, 'UTC')->timezone('Asia/Manila')->toDateString();

            return (empty($filters['mode']) || $r->mode === $filters['mode'])
                && (empty($filters['from']) || $day >= $filters['from']) && (empty($filters['to']) || $day <= $filters['to']);
        };
        $unknown = Progress::where('user_id', $userId)->where('history_unknown', true)->pluck('question_id')->flip();
        // Determine first-ever and latest-overall before applying date or tool filters.
        $first = $all->unique('question_id')->reject(fn ($r) => $unknown->has($r->question_id))->filter($inScope);
        $covered = $all->filter($inScope)->pluck('question_id')->flip();
        $latest = $all->keyBy('question_id');
        $reviewIds = $latest->filter(fn ($r) => ! $r->correct && $inScope($r))->keys()->flip();
        $legacy = Progress::where('user_id', $userId)->where('legacy', true)->whereNotNull('completed_at')->get();
        if (empty($filters['from']) && empty($filters['to']) && empty($filters['mode'])) {
            foreach ($legacy as $result) {
                if ($questions->has($result->question_id)) {
                    $covered->put($result->question_id, true);
                    if (! $latest->has($result->question_id) && ! $result->latest_correct) {
                        $reviewIds->put($result->question_id, true);
                    }
                }
            }
        }
        $review = $questions->filter(fn ($q) => $reviewIds->has($q['id']))->values();
        $topics = $review->groupBy(fn ($q) => $q['subject']."\x1f".$q['topic'])->map(function ($rows) {
            $q = $rows->first();

            return ['subject' => $q['subject'], 'topic' => $q['topic'], 'topic_key' => $q['topic_code'] ?: $q['subject']."\x1f".$q['topic'], 'count' => $rows->count()];
        })->sortByDesc('count')->values();

        return ['first_correct' => $first->where('correct', true)->count(), 'first_total' => $first->count(),
            'covered' => $covered->count(), 'available' => $questions->count(), 'need_review' => $review->count(),
            'topics' => $topics->all(), 'review_ids' => $review->pluck('id')->all(),
            'coverage' => $questions->groupBy('subject')->map(fn ($rows, $subject) => ['subject' => $subject, 'total' => $rows->count(), 'completed' => $rows->filter(fn ($q) => $covered->has($q['id']))->count()])->values()->all(),
            'filters' => $filters];
    }
}
