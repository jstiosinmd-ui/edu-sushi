<?php

namespace App\Http\Controllers;

use App\Models\FlashcardDeck;
use App\Services\Catalog;
use App\Services\Draw;
use App\Services\Selection;
use App\Services\Study;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WorkbenchController extends Controller
{
    public function available(Request $r, Selection $selection, Draw $draw)
    {
        $s = StudyController::settings($r);
        $pool = $selection->pool($r->user(), $s);
        $distinct = collect($pool)->unique(fn ($q) => Catalog::questionHash($q));
        $problem = null;
        try {
            $draw->select($pool, $s);
        } catch (ValidationException $e) {
            $problem = collect($e->errors())->flatten()->first();
        }
        $topics = collect($selection->pool($r->user(), [...$s, 'topics' => []]))->groupBy(fn ($q) => $q['subject']."\x1f".$q['topic'])->map(fn ($qs) => $qs->unique(fn ($q) => Catalog::questionHash($q))->count());

        return response()->json(['available' => $distinct->count(), 'subjects' => $distinct->countBy('subject'), 'topics' => $topics, 'problem' => $problem]);
    }

    public function tray(Request $r, Selection $selection, Study $study)
    {
        $d = $r->validate(['mode' => 'required|in:tutor,memory', 'selected_ids' => 'required|array|min:1|max:500', 'selected_ids.*' => 'required|string|distinct|max:100']);
        $items = $selection->exact($d['selected_ids']);
        $s = ['mode' => 'tutor', 'count' => count($items), 'seed' => random_int(0, 4294967295), 'subjects' => array_values(array_unique(array_column($items, 'subject'))), 'topics' => [], 'difficulty' => 'All', 'pool' => 'all', 'balance' => false, 'minutes' => 30, 'selected_ids' => $d['selected_ids']];
        if ($d['mode'] === 'tutor') {
            $a = $study->start($r->user(), $s);

            return redirect('/attempts/'.$a->id);
        }
        $deck = DB::transaction(function () use ($r, $s, $items) {
            $deck = FlashcardDeck::create(['user_id' => $r->user()->id, 'title' => 'Roll Call · '.count($items).' cards', 'settings' => $s]);
            foreach ($items as $q) {
                $deck->items()->create(['question_id' => $q['id'], 'snapshot' => $q]);
            }

            return $deck;
        });

        return redirect('/flashcards/decks/'.$deck->id);
    }
}
