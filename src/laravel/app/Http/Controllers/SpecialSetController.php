<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\SpecialSets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class SpecialSetController extends Controller
{
    public function index(Request $request, SpecialSets $sets)
    {
        $rows = [];
        foreach (SpecialSets::SLUGS as $slug) {
            $set = $sets->load($slug);
            $answers = $sets->answers($request->user()->id, $set);
            $rows[] = ['slug' => $slug, 'title' => $set['title'], 'summary' => $sets->summary($set, $answers)];
        }

        return Inertia::render('SpecialSets', ['sets' => $rows]);
    }

    public function show(Request $request, string $slug, SpecialSets $sets)
    {
        $set = $sets->load($slug);
        $answers = $sets->answers($request->user()->id, $set);
        $feedback = [];
        $questions = [];
        foreach ($set['items'] as $q) {
            $questions[] = array_intersect_key($q, array_flip(['id', 'number', 'page', 'stem', 'options', 'image', 'review_only']));
            if (array_key_exists($q['number'], $answers)) {
                $feedback[$q['number']] = $sets->feedback($q, $answers[$q['number']]);
            }
        }

        return Inertia::render('SpecialSet', ['set' => ['slug' => $slug, 'title' => $set['title'], 'hash' => $set['hash'], 'note' => $set['note'], 'source_file' => $set['source_file']],
            'questions' => $questions, 'saved' => (object) $feedback, 'summary' => $sets->summary($set, $answers)]);
    }

    public function answer(Request $request, string $slug, SpecialSets $sets)
    {
        $set = $sets->load($slug);
        $data = $request->validate(['number' => 'required|integer|min:1|max:100', 'hash' => 'required|string|size:64', 'answer' => 'nullable|string|in:A,B,C,D,E']);
        abort_unless(hash_equals($set['hash'], $data['hash']), 409, 'This set has been updated. Reload before continuing.');
        $q = $set['items'][$data['number'] - 1];
        if (! $q['review_only'] && ! array_key_exists($data['answer'] ?? '', $q['options'])) {
            throw ValidationException::withMessages(['answer' => 'Choose one of the listed answers.']);
        }
        if ($q['review_only'] && ! empty($data['answer'])) {
            throw ValidationException::withMessages(['answer' => 'This question is for review only.']);
        }

        return DB::transaction(function () use ($request, $sets, $set, $q, $data) {
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $answers = $sets->answers($request->user()->id, $set);
            // Retain the first response, including when a client retries after a timeout.
            $answers[$q['number']] ??= ['answer' => $q['review_only'] ? null : $data['answer'], 'answered_at' => now()->toIso8601String()];
            $now = now();
            $identity = ['user_id' => $request->user()->id, 'slug' => $set['slug'], 'content_hash' => $set['hash']];
            DB::table('special_set_progress')->insertOrIgnore([...$identity, 'answers' => '{}', 'created_at' => $now, 'updated_at' => $now]);
            DB::table('special_set_progress')->where($identity)->update(['answers' => json_encode((object) $answers, JSON_THROW_ON_ERROR), 'updated_at' => $now]);

            return response()->json(['number' => $q['number'], 'feedback' => $sets->feedback($q, $answers[$q['number']]), 'summary' => $sets->summary($set, $answers)]);
        }, 3);
    }

    public function image(string $asset)
    {
        abort_unless($asset === 'physiology-2027-002.png', 404);

        return response()->file(database_path('data/special-sets/'.$asset), ['Cache-Control' => 'private, max-age=86400']);
    }
}
