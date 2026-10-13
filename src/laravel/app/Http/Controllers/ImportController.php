<?php

namespace App\Http\Controllers;

use App\Models\Progress;
use App\Models\Question;
use App\Models\User;
use App\Services\Catalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;

class ImportController extends Controller
{
    public function index()
    {
        return Inertia::render('Import');
    }

    public function store(Request $r)
    {
        $r->validate(['backup' => 'required|file|max:2048', 'confirm' => 'nullable|boolean']);
        $bytes = file_get_contents($r->file('backup')->getRealPath());
        try {
            $p = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            abort(422, 'Invalid JSON backup.');
        }
        abort_unless(is_array($p), 422, 'The backup must be a JSON object.');
        Validator::make($p, ['version' => 'required|in:1,2', 'bankHash' => 'required|in:'.Catalog::HASH, 'answers' => 'present|array',
            'answers.*.answer' => 'required|in:A,B,C,D', 'answers.*.attempts' => 'required|integer|min:1|max:100000',
            'bookmarks' => 'present|array', 'bookmarks.*' => 'string', 'answeredIds' => 'sometimes|array', 'answeredIds.*' => 'string'])->validate();
        $ids = array_unique([...array_keys($p['answers']), ...$p['bookmarks'], ...($p['answeredIds'] ?? [])]);
        $questions = Question::with('currentVersion')->whereIn('id', $ids)->get()->keyBy('id');
        abort_unless($questions->count() === count($ids), 422, 'The backup contains an unknown question ID.');
        $hash = hash('sha256', $bytes);
        $uid = $r->user()->id;
        $summary = ['completed' => count($p['answers']), 'bookmarks' => count($p['bookmarks']), 'hash' => $hash];
        if (! $r->boolean('confirm')) {
            return response()->json(['preview' => $summary]);
        }
        $r->validate(['preview_hash' => 'required|in:'.$hash]);
        DB::transaction(function () use ($uid, $p, $questions, $hash, $summary, $ids) {
            User::whereKey($uid)->lockForUpdate()->firstOrFail();
            if (DB::table('imports')->where('user_id', $uid)->where('kind', 'progress')->where('hash', $hash)->exists()) {
                return;
            }
            foreach ($ids as $id) {
                $record = Progress::firstOrCreate(['user_id' => $uid, 'question_id' => $id]);
                $changes = [];
                if (in_array($id, $p['bookmarks'], true)) {
                    $changes['bookmarked'] = true;
                }
                if ((isset($p['answers'][$id]) || in_array($id, $p['answeredIds'] ?? [], true)) && ! $record->selected_at) {
                    $changes['selected_at'] = now();
                }
                if (isset($p['answers'][$id]) && ! $record->completed_at) {
                    $changes += ['completed_at' => now(), 'legacy' => true, 'history_unknown' => true,
                        'latest_answer' => $p['answers'][$id]['answer'], 'latest_correct' => $p['answers'][$id]['answer'] === $questions[$id]->currentVersion->content['key']];
                }
                $record->update($changes);
            }
            DB::table('imports')->insert(['user_id' => $uid, 'kind' => 'progress', 'hash' => $hash, 'summary' => json_encode($summary), 'created_at' => now(), 'updated_at' => now()]);
        }, 3);

        return response()->json(['message' => 'Progress imported. Existing server results were preserved. Historical dates and first-attempt scores were not inferred.']);
    }
}
