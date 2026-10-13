<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\Attempt;
use App\Models\Progress;
use App\Models\Question;
use App\Services\Achievements;
use App\Services\Analytics;
use App\Services\Catalog;
use App\Services\Study;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class StudyController extends Controller
{
    public static function settings(Request $r): array
    {
        return $r->validate(['mode' => 'required|in:tutor,exam,fresh', 'count' => 'required|integer|min:1|max:10000',
            'seed' => 'required|integer|min:0|max:4294967295', 'subjects' => 'required|array|min:1|max:4',
            'subjects.*' => 'required|distinct|in:'.implode(',', Catalog::SUBJECTS), 'topics' => 'array|max:500', 'topics.*' => 'string|max:600|distinct',
            'difficulty' => 'required|in:All,Easy,Average,Difficult', 'balance' => 'boolean', 'pool' => 'in:all,new,missed,bookmarks', 'minutes' => 'required|integer|min:1|max:360']);
    }

    public static function catalog(): array
    {
        return ['subjects' => Catalog::SUBJECTS, 'topics' => Question::where('active', true)->select('subject', 'topic')->distinct()->orderBy('subject')->orderBy('topic')->get(), 'total' => Question::where('active', true)->count()];
    }

    public function home(Request $r, Analytics $analytics)
    {
        return Inertia::render('Home', ['catalog' => self::catalog(), 'stats' => $analytics->for($r->user()->id),
            'assignments' => Assignment::where('user_id', $r->user()->id)->with('exam:id,title,code')->latest()->get()]);
    }

    public function practice()
    {
        return Inertia::render('Practice', ['catalog' => self::catalog()]);
    }

    public function start(Request $r, Study $study)
    {
        $attempt = $study->start($r->user(), self::settings($r));

        return redirect('/attempts/'.$attempt->id);
    }

    public function assigned(Request $r, Assignment $assignment, Study $study)
    {
        $a = $study->start($r->user(), [], $assignment);

        return redirect('/attempts/'.$a->id);
    }

    private function own(Request $r, Attempt $a): void
    {
        abort_unless($a->user_id === $r->user()->id, 403);
    }

    public function show(Request $r, Attempt $attempt, Study $study)
    {
        $this->own($r, $attempt);

        return Inertia::render('Attempt', ['attempt' => $study->present($attempt), 'bookmarks' => Progress::where('user_id', $r->user()->id)->where('bookmarked', true)->pluck('question_id')]);
    }

    public function answer(Request $r, Attempt $attempt, Study $study)
    {
        $this->own($r, $attempt);
        $d = $r->validate(['item_id' => 'required|integer', 'answer' => 'required|in:A,B,C,D', 'revision' => 'required|integer|min:0', 'check' => 'boolean']);
        $a = $study->answer($attempt, $d['item_id'], $d);

        return response()->json(['attempt' => $study->present($a), 'achievement' => Achievements::for($r->user()->id)]);
    }

    public function finish(Request $r, Attempt $attempt, Study $study)
    {
        $this->own($r, $attempt);
        $a = $study->expire($attempt);
        $study->finish($a);

        return redirect('/attempts/'.$a->id);
    }

    public function heartbeat(Request $r, Attempt $attempt, Study $study)
    {
        $this->own($r, $attempt);
        $study->expire($attempt);
        DB::transaction(function () use ($attempt, $r) {
            $a = Attempt::lockForUpdate()->findOrFail($attempt->id);
            if ($a->status !== 'active') {
                return;
            }
            $delta = $a->heartbeat_at ? (int) $a->heartbeat_at->diffInSeconds(now()) : 0;
            $seconds = $a->active_seconds + (($delta >= 5 && $delta <= 45 && $r->boolean('active')) ? $delta : 0);
            $a->update(['heartbeat_at' => now(), 'active_seconds' => $seconds]);
        });

        return response()->json(['status' => 'ok']);
    }

    public function bookmark(Request $r, Question $question)
    {
        $r->validate(['bookmarked' => 'required|boolean']);
        Progress::updateOrCreate(['user_id' => $r->user()->id, 'question_id' => $question->id], ['bookmarked' => $r->boolean('bookmarked')]);

        return back()->with('message', 'Bookmark updated.');
    }

    public function library(Request $r)
    {
        $q = Question::with('currentVersion')->where('active', true);
        if ($r->filled('subject')) {
            $q->where('subject', $r->string('subject'));
        }
        if ($r->filled('search')) {
            $term = mb_strtolower(mb_substr($r->string('search'), 0, 200));
            $ids = $q->get()->filter(fn ($q) => str_contains(mb_strtolower($q->id.' '.$q->topic.' '.$q->currentVersion->content['stem']), $term))->pluck('id');
            $q->whereIn('id', $ids);
        }
        if ($r->boolean('bookmarks')) {
            $q->whereIn('id', Progress::where('user_id', $r->user()->id)->where('bookmarked', true)->pluck('question_id'));
        }
        $page = $q->orderBy('position')->paginate(12)->withQueryString();
        $page->through(fn ($q) => Catalog::publicQuestion([...$q->currentVersion->content, 'version' => $q->currentVersion->version], true));

        return Inertia::render('Library', ['questions' => $page, 'catalog' => self::catalog(), 'filters' => $r->only('subject', 'search', 'bookmarks'),
            'bookmarks' => Progress::where('user_id', $r->user()->id)->where('bookmarked', true)->pluck('question_id')]);
    }

    public function progress(Request $r, Analytics $analytics)
    {
        $filters = $r->validate(['subject' => 'nullable|string|max:100', 'topic' => 'nullable|string|max:255', 'difficulty' => 'nullable|in:Easy,Average,Difficult', 'mode' => 'nullable|in:tutor,exam,fresh,assigned', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from']);

        return Inertia::render('Progress', ['stats' => $analytics->for($r->user()->id, $filters), 'filters' => $filters, 'catalog' => self::catalog()]);
    }
}
