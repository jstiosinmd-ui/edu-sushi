<?php

namespace App\Http\Controllers;

use App\Models\Flashcard;
use App\Models\FlashcardDeck;
use App\Models\User;
use App\Services\Achievements;
use App\Services\Catalog;
use App\Services\Learning;
use App\Services\WeightedDeck;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class FlashcardController extends Controller
{
    public function index(Request $r)
    {
        return Inertia::render('Flashcards', ['catalog' => StudyController::catalog(),
            'decks' => FlashcardDeck::where('user_id', $r->user()->id)->withCount('items')->latest()->limit(50)->get()]);
    }

    private function filters(Request $r): array
    {
        $d = $r->validate(['subjects' => 'required|array|min:1|max:4', 'subjects.*' => 'distinct|in:'.implode(',', Catalog::SUBJECTS),
            'topic_scope' => 'in:all,custom', 'topics' => 'required_if:topic_scope,custom|array', 'topics.*' => 'string|max:600', 'difficulty' => 'required|in:All,Easy,Average,Difficult',
            'range_start' => 'required|integer|min:1', 'range_end' => 'required|integer|gte:range_start']);
        if ($d['range_end'] - $d['range_start'] >= 500) {
            throw ValidationException::withMessages(['range_end' => 'Choose at most 500 questions in one deck.']);
        }

        return $d;
    }

    private function pool(array $s): array
    {
        return array_values(array_filter(app(Catalog::class)->items(), fn ($q) => in_array($q['subject'], $s['subjects'], true)
            && (empty($s['topics']) || in_array($q['subject']."\x1f".$q['topic'], $s['topics'], true))
            && ($s['difficulty'] === 'All' || $s['difficulty'] === $q['tier'])));
    }

    public function preview(Request $r)
    {
        $s = $this->filters($r);
        $items = $this->pool($s);
        $slice = array_slice($items, $s['range_start'] - 1, min(500, $s['range_end'] - $s['range_start'] + 1));

        return response()->json(['total' => count($items), 'questions' => array_map(fn ($q) => array_intersect_key($q, array_flip(['id', 'subject', 'topic', 'stem'])), $slice)]);
    }

    public function create(Request $r)
    {
        $s = $this->filters($r);
        $d = $r->validate(['title' => 'required|string|max:150', 'selected_ids' => 'required|array|min:1|max:500', 'selected_ids.*' => 'string|distinct']);
        $pool = array_slice($this->pool($s), $s['range_start'] - 1, min(500, $s['range_end'] - $s['range_start'] + 1));
        $items = array_values(array_filter($pool, fn ($q) => in_array($q['id'], $d['selected_ids'], true)));
        abort_unless(count($items) === count($d['selected_ids']), 422, 'Preview the current range and select questions again.');
        $hashes = array_map(fn ($q) => Catalog::stemHash($q['stem']), $items);
        abort_unless(count(array_unique($hashes)) === count($items), 422, 'Your selection contains duplicate question stems. Include only one copy.');
        $deck = DB::transaction(function () use ($r, $s, $d, $items) {
            $deck = FlashcardDeck::create(['user_id' => $r->user()->id, 'title' => $d['title'], 'settings' => $s]);
            foreach ($items as $q) {
                $deck->items()->create(['question_id' => $q['id'], 'snapshot' => $q]);
            }

            return $deck;
        });

        return redirect('/flashcards/decks/'.$deck->id);
    }

    private function own(Request $r, FlashcardDeck $deck): void
    {
        abort_unless($deck->user_id === $r->user()->id, 403);
    }

    private function present(FlashcardDeck $deck): array
    {
        $items = $deck->items()->get();
        $counts = array_fill(0, 6, 0);
        foreach ($items as $i) {
            $counts[$i->confidence ?? 0]++;
        }
        $item = $items->firstWhere('question_id', $deck->active_question_id);

        return ['id' => $deck->id, 'title' => $deck->title, 'total' => $items->count(), 'reviews' => $deck->reviews, 'counts' => $counts,
            'session_number' => $deck->session_number, 'session_reviews' => $deck->reviews - $deck->session_start_reviews,
            'finished' => (bool) $deck->session_finished_at, 'goal_target' => (int) ceil($items->count() * .8),
            'goal_prompt' => ! $deck->session_finished_at && ! $deck->goal_prompt_dismissed && $items->count() > 0 && $counts[5] >= ceil($items->count() * .8),
            'question' => $item && ! $deck->session_finished_at ? [...Catalog::publicQuestion($item->snapshot, $item->answer !== null), 'answer' => $item->answer, 'correct' => $item->answer !== null ? $item->correct : null, 'token' => $item->review_token] : null];
    }

    private function draw(FlashcardDeck $deck): FlashcardDeck
    {
        return DB::transaction(function () use ($deck) {
            $deck = FlashcardDeck::lockForUpdate()->findOrFail($deck->id);
            if (! $deck->active_question_id && ! $deck->session_finished_at) {
                $id = app(WeightedDeck::class)->choose($deck->items()->get(['question_id', 'confidence'])->toArray());
                $deck->items()->where('question_id', $id)->update(['answer' => null, 'correct' => null, 'review_token' => (string) Str::uuid()]);
                $deck->update(['active_question_id' => $id]);
            }

            return $deck;
        }, 3);
    }

    public function show(Request $r, FlashcardDeck $deck)
    {
        $this->own($r, $deck);

        return Inertia::render('FlashcardDeck', ['deck' => $this->present($this->draw($deck))]);
    }

    public function answer(Request $r, FlashcardDeck $deck)
    {
        $this->own($r, $deck);
        $d = $r->validate(['question_id' => 'required|string', 'answer' => 'required|in:A,B,C,D', 'token' => 'required|uuid']);

        return DB::transaction(function () use ($deck, $d) {
            User::whereKey($deck->user_id)->lockForUpdate()->firstOrFail();
            $deck = FlashcardDeck::lockForUpdate()->findOrFail($deck->id);
            abort_if($deck->session_finished_at, 409, 'Resume this session before answering.');
            abort_unless($deck->active_question_id === $d['question_id'], 409, 'This deck moved to another card. Reload to continue.');
            $item = $deck->items()->where('question_id', $d['question_id'])->firstOrFail();
            abort_unless($item->review_token === $d['token'], 409, 'This is an older presentation of this card. Reload the deck.');
            if ($item->answer === null) {
                $item->update(['answer' => $d['answer'], 'correct' => $d['answer'] === $item->snapshot['key']]);
                app(Learning::class)->answer($deck->user_id, 'memory:'.$item->review_token, $item->snapshot, 'memory', $item->correct);
            }
            User::whereKey($deck->user_id)->update(['last_active_at' => now()]);

            return response()->json(['deck' => $this->present($deck)]);
        });
    }

    public function rate(Request $r, FlashcardDeck $deck)
    {
        $this->own($r, $deck);
        $d = $r->validate(['confidence' => 'required|integer|between:1,5', 'token' => 'required|uuid', 'question_id' => 'required|string']);
        $deck = DB::transaction(function () use ($r, $deck, $d) {
            User::whereKey($deck->user_id)->lockForUpdate()->firstOrFail();
            $deck = FlashcardDeck::lockForUpdate()->findOrFail($deck->id);
            if (DB::table('flashcard_reviews')->where('user_id', $r->user()->id)->where('review_token', $d['token'])->exists()) {
                return $deck;
            }
            abort_if($deck->session_finished_at, 409, 'Resume this session before rating.');
            abort_unless($deck->active_question_id === $d['question_id'], 409, 'This deck moved to another card. Reload to continue.');
            $item = $deck->items()->where('question_id', $d['question_id'])->firstOrFail();
            abort_unless($item->answer !== null && $item->review_token === $d['token'], 409, 'Answer the question before rating your familiarity.');
            DB::table('flashcard_reviews')->insert(['user_id' => $r->user()->id, 'question_id' => $item->question_id,
                'flashcard_deck_item_id' => $item->id, 'answer' => $item->answer, 'correct' => $item->correct,
                'confidence' => $d['confidence'], 'review_token' => $d['token'], 'created_at' => now(), 'updated_at' => now()]);
            $item->update(['confidence' => $d['confidence'], 'reviews' => $item->reviews + 1]);
            $global = Flashcard::firstOrCreate(['user_id' => $r->user()->id, 'question_id' => $item->question_id]);
            $global->update(['confidence' => $d['confidence'], 'reviews' => $global->reviews + 1]);
            $deck->update(['active_question_id' => null, 'reviews' => $deck->reviews + 1]);
            $r->user()->update(['last_active_at' => now()]);

            return $deck;
        }, 3);

        return response()->json(['deck' => $this->present($this->draw($deck)), 'achievement' => Achievements::for($r->user()->id), 'community' => CommunityController::summary($r->user())]);
    }

    public function session(Request $r, FlashcardDeck $deck)
    {
        $this->own($r, $deck);
        $d = $r->validate(['action' => 'required|in:finish,resume,continue', 'session_number' => 'required|integer|min:1']);
        $deck = DB::transaction(function () use ($deck, $d) {
            $deck = FlashcardDeck::lockForUpdate()->findOrFail($deck->id);
            abort_unless($deck->session_number === $d['session_number'], 409, 'This session changed. Reload to continue.');
            if ($d['action'] === 'finish') {
                $deck->update(['session_finished_at' => $deck->session_finished_at ?? now()]);
            }
            if ($d['action'] === 'continue' && ! $deck->session_finished_at) {
                $deck->update(['goal_prompt_dismissed' => true]);
            }
            if ($d['action'] === 'resume' && $deck->session_finished_at) {
                $deck->update(['session_finished_at' => null, 'session_number' => $deck->session_number + 1, 'session_start_reviews' => $deck->reviews, 'goal_prompt_dismissed' => false]);
            }

            return $deck;
        }, 3);

        return response()->json(['deck' => $this->present($this->draw($deck)), 'community' => CommunityController::summary($r->user())]);
    }
}
