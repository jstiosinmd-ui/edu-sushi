<?php

namespace App\Services;

use App\Models\Attempt;
use App\Models\FlashcardDeck;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StudySetup
{
    public function preferences(User $user): array
    {
        $saved = $user->study_preferences ?? [];
        $catalog = app(Catalog::class)->summary();
        $available = array_unique(array_column($catalog['topics'], 'subject'));
        $subjects = array_values(array_intersect($saved['subjects'] ?? ($user->fresh_subjects ?: ['Pediatrics']), $available));
        if (! $subjects) {
            $subjects = array_values(array_intersect(['Pediatrics'], $available)) ?: array_slice($available, 0, 1);
        }
        $topics = array_map(fn ($t) => $t['topic_code'] ?: $t['subject']."\x1f".$t['topic'], array_filter($catalog['topics'], fn ($t) => in_array($t['subject'], $subjects, true)));

        return ['mode' => in_array($saved['mode'] ?? '', ['practice', 'flashcards', 'exam'], true) ? $saved['mode'] : 'practice',
            'subjects' => $subjects, 'topics' => array_values(array_intersect($saved['topics'] ?? [], $topics)),
            'count' => max(1, min(100, (int) ($saved['count'] ?? 10))),
            'difficulty' => in_array($saved['difficulty'] ?? '', ['Easy', 'Average', 'Difficult'], true) ? $saved['difficulty'] : 'All',
            'pool' => in_array($saved['pool'] ?? '', ['new', 'missed', 'low', 'bookmarks'], true) ? $saved['pool'] : 'all',
            'minutes' => max(1, min(360, (int) ($saved['minutes'] ?? 30)))];
    }

    public function start(User $user, array $setup): string
    {
        $catalog = app(Catalog::class);
        $validTopics = array_map(fn ($t) => $t['topic_code'] ?: $t['subject']."\x1f".$t['topic'], array_filter($catalog->summary()['topics'], fn ($t) => in_array($t['subject'], $setup['subjects'], true)));
        if (array_diff($setup['topics'], $validTopics)) {
            throw ValidationException::withMessages(['topics' => 'A topic is no longer available in these subjects. Update your selection.']);
        }
        $settings = [...$setup, 'mode' => $setup['mode'] === 'exam' ? 'exam' : 'tutor', 'seed' => random_int(0, 4294967295), 'balance' => false];

        return DB::transaction(function () use ($user, $setup, $settings, $catalog) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($setup['mode'] === 'flashcards') {
                $selected = app(Draw::class)->select(app(Selection::class)->pool($user, $settings), $settings);
                $deck = FlashcardDeck::create(['user_id' => $user->id, 'title' => 'Flashcards · '.$setup['count'].' questions', 'settings' => $settings]);
                foreach ($catalog->hydrate($selected) as $question) {
                    $deck->items()->create(['question_id' => $question['id'], 'snapshot' => $question]);
                }
                $url = '/flashcards/decks/'.$deck->id;
            } else {
                $attempt = app(Study::class)->start($user, $settings);
                $url = '/attempts/'.$attempt->id;
            }
            $user->update(['study_preferences' => $setup]);

            return $url;
        }, 3);
    }

    public function resumable(User $user): array
    {
        $attempts = Attempt::where('user_id', $user->id)->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('deadline')->orWhere('deadline', '>', now()))
            ->withCount(['items', 'items as answered_count' => fn ($q) => $q->whereNotNull('answer')])->latest('updated_at')->limit(6)->get()
            ->map(fn ($a) => ['id' => 'attempt-'.$a->id, 'url' => '/attempts/'.$a->id, 'mode' => $a->mode === 'exam' || $a->mode === 'assigned' ? 'exam' : 'practice',
                'title' => ['tutor' => 'Practice', 'fresh' => 'Daily catch', 'exam' => 'Exam', 'assigned' => 'Assigned exam'][$a->mode],
                'total' => $a->items_count, 'completed' => $a->answered_count, 'deadline' => $a->deadline?->toIso8601String(), 'updated_at' => $a->updated_at]);
        $decks = FlashcardDeck::where('user_id', $user->id)->whereNull('session_finished_at')
            ->withCount(['items', 'items as rated_count' => fn ($q) => $q->whereNotNull('confidence')])->latest('updated_at')->limit(6)->get()
            ->map(fn ($d) => ['id' => 'deck-'.$d->id, 'url' => '/flashcards/decks/'.$d->id, 'mode' => 'flashcards', 'title' => $d->title,
                'total' => $d->items_count, 'completed' => $d->rated_count, 'deadline' => null, 'updated_at' => $d->updated_at]);

        return $attempts->concat($decks)->sortByDesc('updated_at')->values()->all();
    }
}
