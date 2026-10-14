<?php

namespace App\Http\Controllers;

use App\Models\Question;
use App\Models\QuestionReport;
use App\Models\QuestionVersion;
use App\Services\QuestionNotices;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class QuestionFeedbackController extends Controller
{
    public function report(Request $r, Question $question)
    {
        $d = $r->validate(['category' => 'required|in:unclear,answer,reference,typo', 'details' => 'required|string|min:5|max:3000', 'version' => 'required|integer|min:1']);
        $version = QuestionVersion::where('question_id', $question->id)->where('version', $d['version'])->firstOrFail();
        $report = QuestionReport::create(['user_id' => $r->user()->id, 'question_id' => $question->id,
            'question_version_id' => $version->id, 'category' => $d['category'], 'details' => $d['details']]);

        return response()->json(['id' => $report->id, 'message' => 'Sent to Head Chef. You can follow the response in Study Updates.']);
    }

    public function index(Request $r)
    {
        $d = $r->validate(['status' => 'nullable|in:all,open,resolved,dismissed']);
        $q = QuestionReport::with('user:id,username', 'version');
        if (($d['status'] ?? 'open') !== 'all') {
            $q->where('status', $d['status'] ?? 'open');
        }
        $reports = $q->latest()->paginate(20)->withQueryString();
        $history = DB::table('question_report_updates')->whereIn('question_report_id', $reports->pluck('id'))->orderByDesc('id')->get()->groupBy('question_report_id');

        return Inertia::render('QuestionReports', ['reports' => $reports, 'status' => $d['status'] ?? 'open', 'history' => $history]);
    }

    public function resolve(Request $r, QuestionReport $report, QuestionNotices $notices)
    {
        $d = $r->validate(['status' => 'required|in:open,resolved,dismissed', 'resolution' => 'required|string|min:5|max:3000', 'revision' => 'required|integer|min:0']);
        DB::transaction(function () use ($r, $report, $d, $notices) {
            $report = QuestionReport::lockForUpdate()->findOrFail($report->id);
            abort_unless($report->revision === $d['revision'], 409, 'Another administrator updated this report. Reload before saving.');
            $report->update([...$d, 'revision' => $report->revision + 1]);
            $id = DB::table('question_report_updates')->insertGetId(['question_report_id' => $report->id, 'user_id' => $r->user()->id,
                'status' => $d['status'], 'resolution' => $d['resolution'], 'created_at' => now()]);
            $notices->send($report->user_id, 'report:'.$id, $report->question_id, 'Head Chef responded to your report',
                $report->question_id.' · '.$d['status'].': '.$d['resolution']);
            AdminController::audit($r, 'question.report_reviewed', (string) $report->id, ['status' => $d['status']]);
        });

        return back()->with('message', 'Review saved and learner notified in Study Updates.');
    }

    public function updates(Request $r)
    {
        return Inertia::render('StudyUpdates', ['notices' => DB::table('learning_notices')->where('user_id', $r->user()->id)->latest('id')->paginate(20),
            'reports' => QuestionReport::where('user_id', $r->user()->id)->latest()->limit(50)->get(['id', 'question_id', 'category', 'details', 'status', 'resolution', 'created_at'])]);
    }

    public function read(Request $r, int $notice)
    {
        $row = DB::table('learning_notices')->where('id', $notice)->where('user_id', $r->user()->id)->first();
        abort_unless($row, 404);
        DB::table('learning_notices')->where('id', $row->id)->update(['read_at' => $row->read_at ?? now()]);

        return back();
    }
}
