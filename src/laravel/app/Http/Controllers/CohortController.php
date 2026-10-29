<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\Exam;
use App\Models\User;
use App\Services\Catalog;
use App\Services\CohortInsights;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;

class CohortController extends Controller
{
    public function index(Request $r, CohortInsights $insights)
    {
        $d = $r->validate(['cohort' => 'nullable|string|max:100', 'subject' => 'nullable|in:'.implode(',', Catalog::SUBJECTS), 'search' => 'nullable|string|max:80']);
        $users = $insights->learners($d['cohort'] ?? null);
        $items = $insights->items($users)->filter(fn ($q) => (empty($d['subject']) || $q['subject'] === $d['subject'])
            && (empty($d['search']) || str_contains(mb_strtolower($q['id'].' '.$q['topic']), mb_strtolower($d['search']))))->values();
        $page = max(1, $r->integer('page', 1));

        return Inertia::render('Cohort', ['overview' => $insights->overview($users), 'filters' => $d,
            'cohorts' => $insights->learners()->pluck('cohort')->filter()->unique()->sort()->values(),
            'subjects' => Catalog::SUBJECTS, 'items' => new LengthAwarePaginator($items->slice(($page - 1) * 20, 20)->values(), $items->count(), 20, $page,
                ['path' => '/admin/overview', 'query' => $r->query()])]);
    }

    public function assign(Request $r, User $user)
    {
        abort_unless($user->role === 'student' && ! $user->disabled && ! $user->invitation_pending && ! $user->archived_at, 422, 'Choose an active student.');
        $d = $r->validate(['subject' => 'required|in:'.implode(',', Catalog::SUBJECTS), 'topic' => 'nullable|string|max:255',
            'count' => 'required|integer|between:1,50', 'minutes' => 'required|integer|between:5,180', 'days' => 'required|integer|between:1,30']);
        $items = collect(app(Catalog::class)->candidates())->where('subject', $d['subject'])
            ->filter(fn ($q) => empty($d['topic']) || $q['topic'] === $d['topic'])->unique(fn ($q) => Catalog::questionHash($q));
        abort_if($items->count() < $d['count'], 422, 'Only '.$items->count().' questions match. Reduce the count or broaden the topic.');
        $items = app(Catalog::class)->hydrate($items->shuffle()->take($d['count'])->values()->all());
        DB::transaction(function () use ($r, $user, $d, $items) {
            $exam = Exam::create(['user_id' => $r->user()->id, 'title' => 'Focused practice · '.$d['subject'].(! empty($d['topic']) ? ' · '.$d['topic'] : ''),
                'code' => 'FOCUS-'.Str::upper(Str::random(12)), 'settings' => ['subjects' => [$d['subject']], 'count' => count($items), 'mode' => 'assigned', 'targeted' => true], 'items' => $items]);
            Assignment::create(['exam_id' => $exam->id, 'user_id' => $user->id, 'opens_at' => now(), 'closes_at' => now()->addDays($d['days']),
                'minutes' => $d['minutes'], 'max_attempts' => 1, 'release_answers' => true]);
            AdminController::audit($r, 'practice.targeted', (string) $exam->id, ['student' => $user->id, 'subject' => $d['subject'], 'count' => count($items)]);
        });

        return back()->with('message', 'Focused practice assigned. Answers become available after submission.');
    }
}
