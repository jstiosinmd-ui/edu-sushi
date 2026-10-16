<?php

namespace App\Services;

use App\Models\Progress;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StudyHistory
{
    /** Actual submitted answers, including Memory Maki answers awaiting a rating. */
    public function responses(int $userId): Collection
    {
        $attempts = DB::table('attempt_items as i')->join('attempts as a', 'a.id', '=', 'i.attempt_id')
            ->leftJoin('study_events as e', 'e.source', '=', DB::raw("CONCAT('attempt:', i.id)"))
            ->where('a.user_id', $userId)->whereNotNull('i.graded_at')->whereNotNull('i.answer')
            ->select('i.question_id', 'i.snapshot', 'i.answer', 'i.correct', 'i.graded_at', 'a.mode', 'e.id as event_id', 'i.id as response_id')
            ->selectRaw("CONCAT('attempt:', i.id) as source")->get();
        $reviews = DB::table('flashcard_reviews as r')->join('flashcard_deck_items as i', 'i.id', '=', 'r.flashcard_deck_item_id')
            ->leftJoin('study_events as e', 'e.source', '=', DB::raw("CONCAT('memory:', r.review_token)"))
            ->where('r.user_id', $userId)->whereNotNull('r.answer')->whereNotNull('r.correct')
            ->select('r.question_id', 'i.snapshot', 'r.answer', 'r.correct', 'e.id as event_id', 'r.id as response_id')
            ->selectRaw("'memory' as mode, CONCAT('memory:', r.review_token) as source, COALESCE(e.occurred_at, r.created_at) as graded_at")->get();
        $unrated = DB::table('flashcard_deck_items as i')->join('flashcard_decks as d', 'd.id', '=', 'i.flashcard_deck_id')
            ->leftJoin('study_events as e', 'e.source', '=', DB::raw("CONCAT('memory:', i.review_token)"))
            ->where('d.user_id', $userId)->whereNotNull('i.answer')->whereNotNull('i.correct')->whereNotNull('i.review_token')
            ->select('i.question_id', 'i.snapshot', 'i.answer', 'i.correct', 'e.id as event_id', 'i.id as response_id')
            ->selectRaw("'memory' as mode, CONCAT('memory:', i.review_token) as source, COALESCE(e.occurred_at, i.updated_at) as graded_at")->get();

        // A card can still hold the same answer after its rating was recorded.
        // Its review token identifies one answer, regardless of how many reads occur.
        return $attempts->concat($reviews)->concat($unrated)->unique('source')
            ->sortBy(fn ($r) => [$r->graded_at, $r->event_id ?? 0, $r->response_id, $r->source])->values()->map(function ($r) {
                $r->q = json_decode($r->snapshot, true);

                return $r;
            });
    }

    /** Overlay recorded answers on imported progress without rewriting historical data. */
    public function progress(int $userId, ?Collection $responses = null): Collection
    {
        $progress = Progress::where('user_id', $userId)->get()->keyBy('question_id');
        foreach ($responses ?? $this->responses($userId) as $r) {
            $p = $progress->get($r->question_id) ?? new Progress(['question_id' => $r->question_id]);
            $at = Carbon::parse($r->graded_at, 'UTC');
            if (! $p->completed_at || $at->gte($p->completed_at)) {
                $p->fill(['completed_at' => $at, 'latest_correct' => $r->correct, 'latest_answer' => $r->answer, 'legacy' => false]);
                $progress->put($r->question_id, $p);
            }
        }

        return $progress;
    }
}
