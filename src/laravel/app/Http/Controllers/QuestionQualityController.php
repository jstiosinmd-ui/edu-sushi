<?php

namespace App\Http\Controllers;

use App\Models\Question;
use App\Services\Catalog;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class QuestionQualityController extends Controller
{
    public function index(Request $r)
    {
        $d = $r->validate(['filter' => 'nullable|in:unreviewed,duplicates,missing_source,corrections,reviewed,all', 'subject' => 'nullable|in:'.implode(',', Catalog::SUBJECTS), 'search' => 'nullable|string|max:100', 'page' => 'nullable|integer|min:1']);
        $all = Question::with('currentVersion')->orderBy('position')->get();
        $duplicates = $all->where('active', true)->groupBy('stem_hash')->filter(fn ($g) => $g->count() > 1)->map(fn ($g) => $g->pluck('id')->all());
        $reviews = DB::table('question_quality_reviews')->join('users', 'users.id', '=', 'question_quality_reviews.user_id')->select('question_quality_reviews.*', 'users.username')->orderByDesc('question_quality_reviews.id')->get()->groupBy('question_version_id');
        $rows = $all->map(function ($q) use ($duplicates, $reviews) {
            $history = $reviews->get($q->current_version_id, collect());

            return ['id' => $q->id, 'active' => $q->active, 'subject' => $q->subject, 'version_id' => $q->current_version_id,
                'question' => Catalog::publicQuestion([...$q->currentVersion->content, 'version' => $q->currentVersion->version], true),
                'duplicates' => $duplicates->get($q->stem_hash, []), 'history' => $history->values(), 'review' => $history->first()];
        });
        $counts = ['total' => $rows->count(), 'duplicates' => $duplicates->count(), 'unreviewed' => $rows->whereNull('review')->count(),
            'missing_source' => $rows->filter(fn ($q) => trim($q['question']['source']) === '')->count(),
            'corrections' => $rows->filter(fn ($q) => ($q['review']->decision ?? '') === 'correction')->count()];
        $rows = $rows->filter(function ($q) use ($d) {
            if (! empty($d['subject']) && $q['subject'] !== $d['subject']) {
                return false;
            }
            if (! empty($d['search']) && ! str_contains(mb_strtolower($q['id'].' '.$q['question']['stem']), mb_strtolower($d['search']))) {
                return false;
            }

            return match ($d['filter'] ?? 'unreviewed') {
                'duplicates' => count($q['duplicates']) > 1,
                'missing_source' => trim($q['question']['source']) === '',
                'corrections' => ($q['review']->decision ?? '') === 'correction',
                'reviewed' => ($q['review']->decision ?? '') === 'checked',
                'all' => true,
                default => $q['review'] === null,
            };
        })->values();
        $page = (int) ($d['page'] ?? 1);
        $pagination = new LengthAwarePaginator($rows->slice(($page - 1) * 20, 20)->values(), $rows->count(), 20, $page, ['path' => $r->url(), 'query' => $r->query()]);

        return Inertia::render('QuestionQuality', ['questions' => $pagination, 'counts' => $counts, 'filters' => $d, 'subjects' => Catalog::SUBJECTS]);
    }

    public function review(Request $r, Question $question)
    {
        $d = $r->validate(['version_id' => 'required|integer', 'source_checked' => 'required|boolean', 'answer_checked' => 'required|boolean', 'decision' => 'required|in:checked,correction', 'notes' => 'required|string|min:5|max:3000']);
        if ($d['decision'] === 'checked' && (! $d['source_checked'] || ! $d['answer_checked'])) {
            throw ValidationException::withMessages(['decision' => 'Check the cited reference and answer before recording a completed review.']);
        }
        DB::transaction(function () use ($question, $r, $d) {
            $q = Question::lockForUpdate()->findOrFail($question->id);
            abort_unless($q->current_version_id === $d['version_id'], 409, 'The question changed. Reload and review the new version.');
            $version = $d['version_id'];
            unset($d['version_id']);
            DB::table('question_quality_reviews')->insert([...$d, 'question_version_id' => $version, 'user_id' => $r->user()->id, 'created_at' => now()]);
        });

        return back()->with('message', 'Review saved for this question version. Corrections create a new version that needs its own review.');
    }
}
