<?php

namespace App\Http\Controllers;

use App\Models\Question;
use App\Models\User;
use App\Services\Catalog;
use App\Services\Notebook;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class NotebookController extends Controller
{
    public function index(Request $r, Notebook $notebook)
    {
        $d = $r->validate(['subject' => 'nullable|in:'.implode(',', Catalog::SUBJECTS), 'status' => 'nullable|in:all,needs_practice,recovered',
            'reason' => 'nullable|in:unknown,confused,misread,guessed']);
        $all = $notebook->rows($r->user()->id, withContent: false);
        $rows = $all->filter(fn ($q) => (empty($d['subject']) || $q['subject'] === $d['subject'])
            && (empty($d['reason']) || $q['reason'] === $d['reason'])
            && match ($d['status'] ?? 'needs_practice') {
                'needs_practice' => $q['latest_correct'] !== true,
                'recovered' => $q['latest_correct'] === true,
                default => true,
            })->values();
        $page = max(1, $r->integer('page', 1));
        $slice = $rows->slice(($page - 1) * 12, 12)->values();
        $content = collect(app(Catalog::class)->hydrate($slice->all()))->keyBy('id');
        $slice = $slice->map(fn ($q) => [...$q, ...Catalog::publicQuestion($content[$q['id']], true)]);

        return Inertia::render('Notebook', ['questions' => new LengthAwarePaginator($slice, $rows->count(), 12, $page,
            ['path' => '/progress/notebook', 'query' => $r->query()]), 'filters' => $d, 'subjects' => Catalog::SUBJECTS,
            'counts' => ['all' => $all->count(), 'needs_practice' => $all->where('latest_correct', '!==', true)->count(), 'recovered' => $all->where('latest_correct', true)->count()]]);
    }

    public function show(Request $r, Question $question)
    {
        $note = DB::table('study_notes')->where('user_id', $r->user()->id)->where('question_id', $question->id)->first();

        return response()->json(['note' => $note?->note ?? '', 'reason' => $note?->reason ?? '', 'revision' => $note?->revision ?? 0]);
    }

    public function save(Request $r, Question $question)
    {
        $d = $r->validate(['reason' => 'nullable|in:unknown,confused,misread,guessed', 'note' => 'nullable|string|max:2000', 'revision' => 'required|integer|min:0']);

        return DB::transaction(function () use ($r, $question, $d) {
            User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            $key = ['user_id' => $r->user()->id, 'question_id' => $question->id];
            $old = DB::table('study_notes')->where($key)->first();
            abort_unless(($old?->revision ?? 0) === $d['revision'], 409, 'This note was edited on another page. Reopen it before saving.');
            DB::table('study_notes')->updateOrInsert($key, ['reason' => $d['reason'] ?? null, 'note' => $d['note'] ?? null,
                'revision' => $d['revision'] + 1, 'created_at' => $old?->created_at ?? now(), 'updated_at' => now()]);

            return response()->json(['revision' => $d['revision'] + 1]);
        });
    }
}
