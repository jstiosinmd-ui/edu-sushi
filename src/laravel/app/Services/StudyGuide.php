<?php

namespace App\Services;

use App\Models\FlashcardDeck;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StudyGuide
{
    public function events(int $userId): Collection
    {
        // Assigned exams have their own release policy and are excluded from practice guidance.
        return DB::table('study_events')->where('user_id', $userId)->where('mode', '!=', 'assigned')
            ->orderBy('occurred_at')->orderBy('id')->get();
    }

    public function knownFirst(Collection $events): Collection
    {
        $unknown = DB::table('progress')->join('questions', 'questions.id', '=', 'progress.question_id')
            ->whereIn('user_id', $events->pluck('user_id')->unique())->where('history_unknown', true)
            ->get(['user_id', 'stem_hash'])->mapWithKeys(fn ($r) => [$r->user_id.':'.$r->stem_hash => true]);

        return $events->unique(fn ($e) => $e->user_id.':'.$e->question_hash)
            ->reject(fn ($e) => isset($unknown[$e->user_id.':'.$e->question_hash]));
    }

    public function plan(User $user, array $subjects, int $minutes): array
    {
        $events = $this->events($user->id);
        $latest = $events->keyBy('question_hash');
        $items = collect(app(Catalog::class)->items())->whereIn('subject', $subjects)
            ->unique(fn ($q) => Catalog::stemHash($q['stem']));
        $ratings = DB::table('flashcards')->where('user_id', $user->id)->pluck('confidence', 'question_id');
        $day = now()->timezone($user->timezone ?: 'Asia/Manila')->toDateString();
        $ordered = $items->sortBy(function ($q) use ($latest, $ratings, $user, $day) {
            $event = $latest->get(Catalog::stemHash($q['stem']));
            $priority = $event && ! $event->correct ? 0 : (($ratings[$q['id']] ?? 5) <= 2 ? 1 : (! $event ? 2 : 3));

            return $priority.hash('sha256', $user->id.$day.$q['id']);
        })->take([5 => 3, 15 => 10, 30 => 20][$minutes])->values();
        $missed = $ordered->filter(fn ($q) => ($e = $latest->get(Catalog::stemHash($q['stem']))) && ! $e->correct)->count();
        $unseen = $ordered->filter(fn ($q) => ! $latest->has(Catalog::stemHash($q['stem'])))->count();
        $low = $ordered->filter(fn ($q) => ($ratings[$q['id']] ?? 5) <= 2)->count();
        $deck = FlashcardDeck::where('user_id', $user->id)->whereNull('session_finished_at')->with('items')->latest('updated_at')->get()
            ->first(fn ($d) => $d->items->isNotEmpty() && $d->items->every(fn ($i) => in_array($i->snapshot['subject'], $subjects, true)));

        return ['minutes' => $minutes, 'count' => $ordered->count(), 'subjects' => $subjects,
            'selected_ids' => $ordered->pluck('id')->all(), 'missed' => $missed, 'unseen' => $unseen,
            'reason' => $missed ? 'Previously missed questions to revisit: '.$missed.'.' : ($low ? 'Questions rated 1 or 2 to revisit: '.$low.'.' : ($unseen ? 'Start with questions you have not answered in practice.' : 'Revisit familiar questions to check your recall.')),
            'coverage' => $ordered->countBy('subject')->all(),
            'resume' => $deck ? ['id' => $deck->id, 'title' => $deck->title] : null];
    }
}
