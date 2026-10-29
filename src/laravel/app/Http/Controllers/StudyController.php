<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\Attempt;
use App\Models\Progress;
use App\Models\Question;
use App\Models\User;
use App\Services\Achievements;
use App\Services\Analytics;
use App\Services\Catalog;
use App\Services\CatchReminders;
use App\Services\FreshCatch;
use App\Services\Habits;
use App\Services\ProgressScope;
use App\Services\Study;
use App\Services\StudyGuide;
use App\Services\StudySetup;
use App\Services\SubjectMap;
use App\Services\Taxonomy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class StudyController extends Controller
{
    public static function settings(Request $r): array
    {
        return $r->validate(['mode' => 'required|in:tutor,exam,fresh', 'count' => 'required|integer|min:1|max:10000',
            'seed' => 'required|integer|min:0|max:4294967295', 'subjects' => 'required|array|min:1|max:'.count(Catalog::SUBJECTS),
            'subjects.*' => 'required|distinct|in:'.implode(',', Catalog::SUBJECTS), 'topics' => 'required_if:topic_scope,custom|array|max:500', 'topics.*' => 'string|max:600|distinct',
            'difficulty' => 'required|in:All,Easy,Average,Difficult', 'balance' => 'boolean', 'allocation' => 'array|max:'.count(Catalog::SUBJECTS), 'allocation.*' => 'integer|min:0|max:10000', 'topic_scope' => 'in:all,custom', 'pool' => 'in:all,new,missed,low,bookmarks', 'minutes' => 'required|integer|min:1|max:360',
            'blueprint' => 'array|max:120', 'blueprint.*' => 'array:subject,topic,difficulty,count', 'blueprint.*.subject' => 'required|in:'.implode(',', Catalog::SUBJECTS),
            'blueprint.*.topic' => 'nullable|string|max:255', 'blueprint.*.difficulty' => 'required|in:All,Easy,Average,Difficult', 'blueprint.*.count' => 'required|integer|min:1|max:10000',
            'fresh_unseen' => 'integer|min:0|max:10', 'fresh_balance' => 'boolean']);
    }

    public static function catalog(): array
    {
        return app(Catalog::class)->summary();
    }

    public function home(Request $r, Analytics $analytics)
    {
        $u = $r->user();
        $habits = app(Habits::class)->for($u);
        $attempt = Attempt::where('user_id', $u->id)->where('mode', 'fresh')->where('status', 'active')->latest('id')->first();
        $available = app(CatchReminders::class)->available($u);
        $daily = [...$habits, 'attempt' => $attempt?->id, 'answered' => $attempt?->items()->whereNotNull('answer')->count() ?? 0,
            'subjects' => $u->fresh_subjects ?: Catalog::SUBJECTS, 'available' => $available, 'push_enabled' => $u->reminder_enabled,
            'remind' => $available && app(CatchReminders::class)->incomplete($u)];

        return Inertia::render('Home', ['catalog' => self::catalog(), 'stats' => $analytics->for($r->user()->id),
            'preferences' => app(StudySetup::class)->preferences($u), 'resumable' => app(StudySetup::class)->resumable($u),
            'daily' => $daily,
            'assignments' => Assignment::where('user_id', $r->user()->id)->with('exam:id,title,code')->latest()->get()]);
    }

    public function practice(Request $r)
    {
        return Inertia::render('Practice', ['catalog' => self::catalog(), 'freshSubjects' => $r->user()->fresh_subjects ?: Catalog::SUBJECTS,
            'activeCatch' => Attempt::where('user_id', $r->user()->id)->where('mode', 'fresh')->where('status', 'active')->latest('id')->value('id'),
            'today' => $r->query('mode') === 'bento' ? app(StudyGuide::class)->plan($r->user(), $r->user()->fresh_subjects ?: Catalog::SUBJECTS, 15) : null,
            'freshPreferences' => ['fresh_unseen' => $r->user()->fresh_unseen, 'fresh_balance' => $r->user()->fresh_balance]]);
    }

    public function freshPreview(Request $r, FreshCatch $fresh)
    {
        $s = self::settings($r);
        $items = $fresh->select($r->user()->id, $s);

        return response()->json(['unseen' => collect($items)->where('fresh_kind', 'unseen')->count(),
            'missed' => collect($items)->where('fresh_kind', 'missed')->count(), 'coverage' => collect($items)->countBy('subject')]);
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
        $d = $r->validate(['item_id' => 'required|integer', 'answer' => 'required|in:A,B,C,D', 'revision' => 'required|integer|min:0', 'check' => 'boolean', 'request_token' => 'nullable|uuid']);
        $a = $study->answer($attempt, $d['item_id'], $d);

        return response()->json(['attempt' => $study->present($a), 'achievement' => Achievements::for($r->user()->id), 'community' => CommunityController::summary($r->user())]);
    }

    public function finish(Request $r, Attempt $attempt, Study $study)
    {
        $this->own($r, $attempt);
        $a = $study->expire($attempt);
        $a = $study->finish($a);

        if ($r->expectsJson()) {
            return response()->json(['attempt' => $study->present($a), 'achievement' => Achievements::for($r->user()->id), 'community' => CommunityController::summary($r->user())]);
        }

        return redirect('/attempts/'.$a->id);
    }

    public function heartbeat(Request $r, Attempt $attempt, Study $study)
    {
        $this->own($r, $attempt);
        $study->expire($attempt);
        DB::transaction(function () use ($attempt, $r) {
            User::whereKey($attempt->user_id)->lockForUpdate()->firstOrFail();
            $a = Attempt::lockForUpdate()->findOrFail($attempt->id);
            if ($a->status !== 'active') {
                return;
            }
            $delta = $a->heartbeat_at ? (int) $a->heartbeat_at->diffInSeconds(now()) : 0;
            $seconds = $a->active_seconds + (($delta >= 5 && $delta <= 45 && $r->boolean('active')) ? $delta : 0);
            $a->update(['heartbeat_at' => now(), 'active_seconds' => $seconds]);
            if ($r->boolean('active')) {
                $r->user()->update(['last_active_at' => now()]);
            }
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
        $r->validate(['view' => 'nullable|in:all,new,missed,bookmarks', 'subject' => 'nullable|in:'.implode(',', Catalog::SUBJECTS), 'search' => 'nullable|string|max:200']);
        $q = Question::with('currentVersion')->where('active', true);
        if ($r->filled('subject')) {
            $q->where('subject', $r->string('subject'));
        }
        if ($r->filled('search')) {
            $term = mb_strtolower(mb_substr($r->string('search'), 0, 200));
            $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
            $q->whereRaw("LOWER(id || ' ' || topic || ' ' || COALESCE((SELECT content->>'stem' FROM question_versions WHERE question_versions.id = questions.current_version_id), '')) LIKE ?", [$pattern]);
        }
        $view = $r->input('view', $r->boolean('bookmarks') ? 'bookmarks' : 'all');
        if ($view !== 'all') {
            $q->whereIn('id', array_column(app(Study::class)->eligible($r->user()->id, ['mode' => 'tutor', 'pool' => $view]), 'id'));
        }
        $page = $q->orderBy('position')->paginate(12)->withQueryString();
        $page->through(fn ($q) => Catalog::publicQuestion([...$q->currentVersion->content, 'version' => $q->currentVersion->version], true));

        return Inertia::render('Library', ['questions' => $page, 'catalog' => self::catalog(), 'filters' => $r->only('subject', 'search', 'bookmarks', 'view'),
            'bookmarks' => Progress::where('user_id', $r->user()->id)->where('bookmarked', true)->pluck('question_id')]);
    }

    public function progress(Request $r, Analytics $analytics)
    {
        $filters = $this->progressFilters($r);

        return Inertia::render('Progress', ['stats' => $analytics->for($r->user()->id, $filters), 'filters' => $filters, 'catalog' => self::catalog(),
            'subjectMap' => app(SubjectMap::class)->for($r->user()->id)]);
    }

    private function progressFilters(Request $r): array
    {
        return ProgressScope::dates($r->validate(['period' => 'nullable|in:all,1,7,30,custom', 'subject' => 'nullable|in:'.implode(',', Catalog::SUBJECTS), 'topic' => 'nullable|string|max:600', 'difficulty' => 'nullable|in:Easy,Average,Difficult', 'mode' => 'nullable|in:tutor,exam,fresh,memory,assigned', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from']));
    }

    public function review(Request $r, ProgressScope $scope, Catalog $catalog, Study $study)
    {
        $filters = $this->progressFilters($r);
        $topic = $r->validate(['review_subject' => 'nullable|in:'.implode(',', Catalog::SUBJECTS), 'review_topic' => 'nullable|string|max:600']);
        $ids = $scope->for($r->user()->id, $filters)['review_ids'];
        $selected = collect($catalog->candidates(['ids' => $ids]))
            ->filter(fn ($q) => (empty($topic['review_subject']) || $q['subject'] === $topic['review_subject'])
                && (empty($topic['review_topic']) || Taxonomy::matches($q, $topic['review_topic'])))
            ->unique(fn ($q) => Catalog::questionHash($q))->take(20)->values();
        if ($selected->isEmpty()) {
            throw ValidationException::withMessages(['review' => 'No questions still need review in these filters. Refresh your progress.']);
        }
        $attempt = $study->start($r->user(), ['mode' => 'tutor', 'count' => $selected->count(), 'selected_ids' => $selected->pluck('id')->all(),
            'subjects' => $selected->pluck('subject')->unique()->values()->all(), 'topics' => [], 'difficulty' => 'All', 'pool' => 'all', 'balance' => false, 'minutes' => 30, 'seed' => random_int(0, 4294967295)]);

        return redirect('/attempts/'.$attempt->id);
    }
}
