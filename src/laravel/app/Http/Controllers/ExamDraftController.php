<?php

namespace App\Http\Controllers;

use App\Models\Exam;
use App\Models\ExamDraft;
use App\Services\Catalog;
use App\Services\Draw;
use App\Services\Selection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ExamDraftController extends Controller
{
    private function own(Request $r, ExamDraft $draft): void
    {
        abort_unless($draft->user_id === $r->user()->id, 403);
        abort_if($draft->expires_at->isPast(), 410, 'This preview expired. Make a new preview.');
    }

    private function present(ExamDraft $draft): array
    {
        return ['id' => $draft->id, 'title' => $draft->title, 'revision' => $draft->revision, 'settings' => $draft->settings, 'items' => array_map(fn ($q) => Catalog::publicQuestion($q, true), $draft->items)];
    }

    public function preview(Request $r, Draw $draw, Selection $selection)
    {
        $s = StudyController::settings($r);
        $r->validate(['title' => 'required|string|max:150']);
        $items = $draw->select($selection->pool($r->user(), $s), $s);
        $draft = ExamDraft::create(['id' => (string) Str::uuid(), 'user_id' => $r->user()->id, 'title' => $r->input('title'), 'settings' => $s, 'items' => $items, 'expires_at' => now()->addHours(24)]);

        return response()->json($this->present($draft));
    }

    public function replace(Request $r, ExamDraft $draft, Selection $selection)
    {
        $this->own($r, $draft);
        $d = $r->validate(['position' => 'required|integer|min:0', 'revision' => 'required|integer|min:0']);

        return DB::transaction(function () use ($r, $draft, $selection, $d) {
            $draft = ExamDraft::lockForUpdate()->findOrFail($draft->id);
            abort_if($draft->saved_exam_id || $draft->revision !== $d['revision'], 409, 'This preview changed. Preview again.');
            $items = $draft->items;
            abort_unless(isset($items[$d['position']]), 422);
            $old = $items[$d['position']];
            $hashes = array_map(fn ($q) => Catalog::stemHash($q['stem']), $items);
            $choices = array_values(array_filter($selection->pool($r->user(), $draft->settings), fn ($q) => $q['subject'] === $old['subject']
                && (empty($draft->settings['blueprint']) || ($q['topic'] === $old['topic'] && $q['tier'] === $old['tier']))
                && ! in_array(Catalog::stemHash($q['stem']), $hashes, true)));
            if (! $choices) {
                throw ValidationException::withMessages(['replacement' => 'No unused questions remain in this subject with these filters.']);
            }
            $items[$d['position']] = $choices[random_int(0, count($choices) - 1)];
            $draft->update(['items' => $items, 'revision' => $draft->revision + 1]);

            return response()->json($this->present($draft));
        });
    }

    public function save(Request $r, ExamDraft $draft)
    {
        $this->own($r, $draft);
        $d = $r->validate(['revision' => 'required|integer|min:0']);
        DB::transaction(function () use ($r, $draft, $d) {
            $draft = ExamDraft::lockForUpdate()->findOrFail($draft->id);
            if ($draft->saved_exam_id) {
                return;
            }
            abort_unless($draft->revision === $d['revision'], 409, 'This preview changed. Review it again.');
            $exam = Exam::create(['user_id' => $r->user()->id, 'title' => $draft->title, 'code' => 'SUSHI-'.strtoupper(Str::random(10)), 'settings' => $draft->settings, 'items' => $draft->items]);
            $draft->update(['saved_exam_id' => $exam->id]);
        });

        return redirect('/exams')->with('message', 'Bento saved. Your reviewed questions and answer key are frozen.');
    }

    public function duplicate(Request $r, Exam $exam)
    {
        abort_unless($exam->user_id === $r->user()->id || $r->user()->role === 'admin', 403);
        $draft = ExamDraft::create(['id' => (string) Str::uuid(), 'user_id' => $r->user()->id, 'title' => mb_substr($exam->title.' · copy', 0, 150), 'settings' => $exam->settings, 'items' => $exam->items, 'expires_at' => now()->addHours(24)]);

        return response()->json($this->present($draft));
    }

    public function preset(Request $r)
    {
        $s = StudyController::settings($r);
        $d = $r->validate(['name' => 'required|string|max:80', 'shared' => 'boolean', 'cohort' => 'nullable|string|max:80']);
        abort_if(($d['shared'] ?? false) && $r->user()->role !== 'admin', 403);
        app(Draw::class)->select(app(Selection::class)->pool($r->user(), $s), $s);
        DB::table('study_presets')->updateOrInsert(['user_id' => $r->user()->id, 'name' => trim($d['name'])], ['settings' => json_encode($s),
            'shared' => $d['shared'] ?? false, 'cohort' => ! empty($d['shared']) ? (trim($d['cohort'] ?? '') ?: null) : null, 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('message', 'Bento preset saved.');
    }
}
