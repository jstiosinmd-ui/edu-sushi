<?php

namespace App\Http\Controllers;

use App\Models\FlashcardDeck;
use App\Models\MemoryExitQuiz;
use App\Services\Catalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class MemoryExitController extends Controller
{
    public function start(Request $r, FlashcardDeck $deck)
    {
        abort_unless($deck->user_id === $r->user()->id, 403);
        $d = $r->validate(['session_number' => 'required|integer|min:1']);
        $quiz = DB::transaction(function () use ($r, $deck, $d) {
            $deck = FlashcardDeck::lockForUpdate()->findOrFail($deck->id);
            abort_unless($deck->session_number === $d['session_number'], 409, 'This session changed. Reload the deck.');
            $existing = MemoryExitQuiz::where('flashcard_deck_id', $deck->id)->where('session_number', $deck->session_number)->first();
            if ($existing) {
                return $existing;
            }
            $items = $deck->items()->get();
            abort_unless($items->count() && $items->where('confidence', 5)->count() >= ceil($items->count() * .8), 422, 'Reach 80% rated 5 to unlock this check.');
            $sample = $items->shuffle()->take(5)->map(fn ($i) => ['question' => $i->snapshot, 'confidence' => $i->confidence])->values()->all();

            return MemoryExitQuiz::create(['user_id' => $r->user()->id, 'flashcard_deck_id' => $deck->id, 'session_number' => $deck->session_number, 'items' => $sample]);
        }, 3);

        return redirect('/memory-checks/'.$quiz->id);
    }

    public function show(Request $r, MemoryExitQuiz $quiz)
    {
        abort_unless($quiz->user_id === $r->user()->id, 403);
        $done = $quiz->finished_at !== null;

        return Inertia::render('MemoryExit', ['quiz' => ['id' => $quiz->id, 'deck_id' => $quiz->flashcard_deck_id,
            'finished' => $done, 'score' => $quiz->score, 'answers' => $quiz->answers,
            'items' => array_map(fn ($i) => ['question' => Catalog::publicQuestion($i['question'], $done),
                'confidence' => $done ? $i['confidence'] : null], $quiz->items)]]);
    }

    public function submit(Request $r, MemoryExitQuiz $quiz)
    {
        abort_unless($quiz->user_id === $r->user()->id, 403);
        $d = $r->validate(['answers' => 'required|array|min:1|max:5', 'answers.*' => 'required|in:A,B,C,D']);
        DB::transaction(function () use ($quiz, $d) {
            $quiz = MemoryExitQuiz::lockForUpdate()->findOrFail($quiz->id);
            if ($quiz->finished_at) {
                return;
            }
            $ids = array_map(fn ($i) => $i['question']['id'], $quiz->items);
            abort_unless(count($ids) === count($d['answers']) && ! array_diff($ids, array_keys($d['answers'])), 422, 'Answer every question in this check.');
            $score = count(array_filter($quiz->items, fn ($i) => $d['answers'][$i['question']['id']] === $i['question']['key']));
            $quiz->update(['answers' => $d['answers'], 'score' => $score, 'finished_at' => now()]);
            // A check measures recall separately; it does not award practice points or rewrite confidence.
        }, 3);

        return redirect('/memory-checks/'.$quiz->id);
    }
}
