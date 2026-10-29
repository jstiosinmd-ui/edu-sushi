<?php

namespace App\Http\Controllers;

use App\Jobs\BuildExamPdfs;
use App\Models\Exam;
use App\Models\PdfExport;
use App\Models\User;
use App\Services\Catalog;
use App\Services\Draw;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;

class ExamController extends Controller
{
    public function index(Request $r)
    {
        $q = Exam::with('exports');
        if ($r->user()->role !== 'admin') {
            $q->where('user_id', $r->user()->id);
        }
        $exams = $q->latest()->paginate(15);
        $exams->through(fn ($exam) => ['id' => $exam->id, 'title' => $exam->title, 'code' => $exam->code, 'settings' => $exam->settings, 'items_count' => count($exam->items), 'exports' => $exam->exports]);

        return Inertia::render('Exams', ['catalog' => StudyController::catalog(), 'exams' => $exams,
            'presets' => DB::table('study_presets')->where(function ($q) use ($r) {
                $q->where('user_id', $r->user()->id)->orWhere(function ($shared) use ($r) {
                    $shared->where('shared', true)->where(function ($cohort) use ($r) {
                        $cohort->whereNull('cohort');
                        if ($r->user()->cohort) {
                            $cohort->orWhere('cohort', $r->user()->cohort);
                        }
                        if ($r->user()->role === 'admin') {
                            $cohort->orWhereNotNull('cohort');
                        }
                    });
                });
            })->get()->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'shared' => $p->shared, 'cohort' => $p->cohort, 'settings' => json_decode($p->settings, true)]),
            'students' => $r->user()->role === 'admin' ? User::where('role', 'student')->where('disabled', false)->where('invitation_pending', false)->whereNull('archived_at')->get(['id', 'username']) : []]);
    }

    public function store(Request $r, Catalog $catalog, Draw $draw)
    {
        $s = StudyController::settings($r);
        $r->validate(['title' => 'required|string|max:150']);
        $items = $catalog->hydrate($draw->select($catalog->candidates($s), $s));
        Exam::create(['user_id' => $r->user()->id, 'title' => $r->string('title')->toString(), 'code' => 'SUSHI-'.strtoupper(Str::random(10)), 'settings' => $s, 'items' => $items]);

        return back()->with('message', 'Exam saved. Its question versions and item order are frozen.');
    }

    private function allowed(Request $r, Exam $exam): void
    {
        abort_unless($exam->user_id === $r->user()->id || $r->user()->role === 'admin', 403);
    }

    public function export(Request $r, Exam $exam)
    {
        $this->allowed($r, $exam);
        $r->validate(['explanations' => 'required|boolean']);
        $export = PdfExport::create(['exam_id' => $exam->id, 'user_id' => $r->user()->id, 'explanations' => $r->boolean('explanations')]);
        BuildExamPdfs::dispatch($export->id);

        return back()->with('message', 'PDFs queued. The download links will appear automatically.');
    }

    public function download(Request $r, PdfExport $export, string $kind)
    {
        $this->allowed($r, $export->exam);
        abort_unless(in_array($kind, ['paper', 'sheet', 'key'], true) && $export->status === 'ready', 404);

        return Storage::disk('local')->download('exams/'.$export->id.'/'.$kind.'.pdf', $export->exam->code.'-'.$kind.'.pdf', ['Cache-Control' => 'private, no-store']);
    }
}
