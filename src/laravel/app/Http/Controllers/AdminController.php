<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\Exam;
use App\Models\Question;
use App\Models\User;
use App\Services\Analytics;
use App\Services\Catalog;
use App\Services\Habits;
use App\Services\Passport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AdminController extends Controller
{
    public static function audit(Request $r, string $action, string $target, array $details = []): void
    {
        DB::table('audit_logs')->insert(['user_id' => $r->user()->id, 'action' => $action, 'target' => $target, 'details' => json_encode($details), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function index(Request $r, Analytics $analytics)
    {
        $users = User::orderBy('username')->get();
        $student = $r->integer('student') ?: ($users->where('role', 'student')->first()?->id);
        abort_if($student && ! $users->contains('id', $student), 404);

        return Inertia::render('Admin', ['users' => $users->map(fn ($u) => $u->only('id', 'username', 'role', 'disabled', 'cohort', 'last_active_at')),
            'student' => $student, 'stats' => $student ? $analytics->for($student, $r->only('subject', 'topic', 'difficulty', 'mode', 'from', 'to')) : null,
            'engagement' => $student ? [
                'habits' => app(Habits::class)->for(User::findOrFail($student)),
                'badges' => count(array_filter(app(Passport::class)->for(User::findOrFail($student)), fn ($b) => $b['earned_at'])),
                'last_seven_days' => DB::table('daily_catches')->where('user_id', $student)->where('completed_at', '>=', now()->subDays(7))->count(),
                'reminders_sent' => DB::table('catch_reminders')->where('user_id', $student)->whereNotNull('sent_at')->count(),
                'reminders_clicked' => DB::table('catch_reminders')->where('user_id', $student)->whereNotNull('clicked_at')->count(),
                'confidence' => DB::table('flashcards')->where('user_id', $student)->selectRaw('confidence, count(*) as total')->groupBy('confidence')->get(),
                'activity' => DB::table('study_events')->where('user_id', $student)->selectRaw('mode, count(*) as answers, count(distinct question_hash) as questions')->groupBy('mode')->get(),
            ] : null,
            'catalog' => StudyController::catalog(), 'filters' => $r->only('subject', 'topic', 'difficulty', 'mode', 'from', 'to'),
            'audits' => DB::table('audit_logs')->latest()->limit(30)->get(), 'assignments' => Assignment::with('exam:id,title', 'user:id,username')->latest()->limit(100)->get()]);
    }

    public function createUser(Request $r)
    {
        $d = $r->validate(['username' => 'required|regex:/^[a-z0-9_]{3,40}$/|unique:users', 'password' => 'required|string|min:8|max:100', 'cohort' => 'nullable|string|max:100']);
        $u = User::create([...$d, 'name' => $d['username'], 'role' => 'student', 'must_change_password' => true]);
        self::audit($r, 'user.created', (string) $u->id);

        return back()->with('message', 'Student account created.');
    }

    public function updateUser(Request $r, User $user)
    {
        $d = $r->validate(['disabled' => 'required|boolean', 'cohort' => 'nullable|string|max:100', 'password' => 'nullable|string|min:8|max:100']);
        abort_if($user->id === $r->user()->id && $d['disabled'], 422, 'You cannot disable your own account.');
        $updates = ['disabled' => $d['disabled'], 'cohort' => $d['cohort'] ?? null];
        if (! empty($d['password'])) {
            $updates += ['password' => $d['password'], 'must_change_password' => true];
        }
        if ($d['disabled'] || ! empty($d['password'])) {
            $updates['auth_version'] = $user->auth_version + 1;
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }
        $user->update($updates);
        self::audit($r, 'user.updated', (string) $user->id, ['disabled' => $d['disabled'], 'password_reset' => ! empty($d['password'])]);

        return back()->with('message', 'Student account updated.');
    }

    public function questions(Request $r)
    {
        $q = Question::with('currentVersion');
        if ($r->filled('search')) {
            $q->where(fn ($q) => $q->where('id', 'like', '%'.$r->string('search').'%')->orWhere('topic', 'like', '%'.$r->string('search').'%'));
        }

        return Inertia::render('Questions', ['questions' => $q->orderBy('position')->paginate(20)->withQueryString(), 'search' => $r->string('search')->toString()]);
    }

    public function edit(Request $r, Question $question, Catalog $catalog)
    {
        $d = $r->validate(['content' => 'required|array', 'active' => 'required|boolean']);
        $d['content']['id'] = $question->id;
        DB::transaction(function () use ($r, $question, $catalog, $d) {
            Question::whereKey($question->id)->lockForUpdate()->firstOrFail();
            $catalog->import(['items' => [$d['content']]], $r->user()->id);
            $question->update(['active' => $d['active']]);
            self::audit($r, 'question.versioned', $question->id);
        });

        return back()->with('message', 'Question saved. Historical attempt versions are preserved.');
    }

    public function import(Request $r, Catalog $catalog)
    {
        $r->validate(['bank' => 'required|file|max:30000', 'publish' => 'nullable|boolean']);
        $bytes = file_get_contents($r->file('bank')->getRealPath());
        try {
            $bank = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            abort(422, 'Invalid JSON bank.');
        }
        abort_unless(is_array($bank), 422, 'The bank must be a JSON object.');
        $catalog->validate($bank);
        $summary = ['items' => count($bank['items']), 'sha256' => hash('sha256', $bytes), 'subjects' => array_count_values(array_column($bank['items'], 'subject'))];
        if (! $r->boolean('publish')) {
            return response()->json(['preview' => $summary]);
        }
        $r->validate(['preview_hash' => 'required|in:'.$summary['sha256']]);
        $result = $catalog->import($bank, $r->user()->id);
        self::audit($r, 'bank.imported', $summary['sha256'], $result);

        return response()->json(['message' => 'Question bank published.', 'result' => $result]);
    }

    public function assign(Request $r, Exam $exam)
    {
        $d = $r->validate(['students' => 'required|array|min:1', 'students.*' => ['integer', Rule::exists('users', 'id')->where('role', 'student')],
            'opens_at' => 'required|date', 'closes_at' => 'required|date|after:opens_at', 'minutes' => 'required|integer|between:1,360', 'max_attempts' => 'required|integer|between:1,20', 'release_answers' => 'required|boolean']);
        foreach ($d['students'] as $id) {
            Assignment::updateOrCreate(['exam_id' => $exam->id, 'user_id' => $id], array_diff_key($d, ['students' => true]));
        }
        self::audit($r, 'exam.assigned', (string) $exam->id, ['students' => $d['students']]);

        return back()->with('message', 'Assignments saved.');
    }

    public function release(Request $r, Assignment $assignment)
    {
        $r->validate(['release_answers' => 'required|boolean']);
        $assignment->update(['release_answers' => $r->boolean('release_answers')]);
        self::audit($r, 'assignment.feedback', (string) $assignment->id, ['released' => $assignment->release_answers]);

        return back()->with('message', 'Feedback policy updated.');
    }

    public function csv(Request $r, Analytics $analytics)
    {
        $r->validate(['student' => 'required|integer|exists:users,id']);
        $stats = $analytics->for($r->integer('student'), $r->only('subject', 'topic', 'difficulty', 'mode', 'from', 'to'));
        self::audit($r, 'analytics.exported', (string) $r->integer('student'));

        return response()->streamDownload(function () use ($stats) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Subject / topic', 'Correct responses', 'Graded responses'], ',', '"', '');
            foreach ($stats['groups'] as $g) {
                $label = preg_match('/^[=+\-@]/', $g['label']) ? "'".$g['label'] : $g['label'];
                fputcsv($out, [$label, $g['correct'], $g['answered']], ',', '"', '');
            }
            fclose($out);
        }, 'sushi-analytics.csv', ['Content-Type' => 'text/csv']);
    }
}
