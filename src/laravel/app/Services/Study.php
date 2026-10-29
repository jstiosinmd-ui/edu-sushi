<?php

namespace App\Services;

use App\Models\Assignment;
use App\Models\Attempt;
use App\Models\AttemptItem;
use App\Models\Progress;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Study
{
    public function __construct(private Catalog $catalog, private Draw $draw) {}

    public function eligible(int $userId, array $settings): array
    {
        if (! empty($settings['selected_ids'])) {
            return app(Selection::class)->exact($settings['selected_ids']);
        }
        if (($settings['mode'] ?? '') === 'fresh') {
            return app(FreshCatch::class)->eligible($userId);
        }
        $items = $this->catalog->candidates($settings);
        $ratings = ($settings['pool'] ?? '') === 'low' ? DB::table('flashcards')->where('user_id', $userId)->where('confidence', '<=', 2)->pluck('question_id')->flip() : collect();
        $progress = in_array($settings['pool'] ?? 'all', ['new', 'missed'], true)
            ? app(StudyHistory::class)->progress($userId)
            : Progress::where('user_id', $userId)->get()->keyBy('question_id');

        $seen = DB::table('study_events')->where('user_id', $userId)->pluck('question_hash')->flip();

        return array_values(array_filter($items, function ($q) use ($progress, $settings, $seen, $ratings) {
            $p = $progress[$q['id']] ?? null;

            return match ($settings['pool'] ?? 'all') {
                'new' => ! $p?->completed_at && ! $seen->has(Catalog::questionHash($q)), 'missed' => $p?->completed_at && ! $p->latest_correct,
                'bookmarks' => (bool) $p?->bookmarked, 'low' => $ratings->has($q['id']), default => true
            };
        }));
    }

    public function start(User $user, array $settings, ?Assignment $assignment = null): Attempt
    {
        return DB::transaction(function () use ($user, $settings, $assignment) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($assignment) {
                abort_unless($assignment->user_id === $user->id, 403);
                $assignment->refresh();
                if (now()->lt($assignment->opens_at) || now()->gte($assignment->closes_at)) {
                    throw ValidationException::withMessages(['assignment' => 'This assignment is not open.']);
                }
                if (Attempt::where('assignment_id', $assignment->id)->count() >= $assignment->max_attempts) {
                    throw ValidationException::withMessages(['assignment' => 'You have used all assigned attempts.']);
                }
                $items = $assignment->exam->items;
                $deadline = now()->addMinutes($assignment->minutes)->min($assignment->closes_at);
                $settings = $assignment->exam->settings;
                $mode = 'assigned';
            } else {
                $mode = $settings['mode'];
                if ($mode === 'fresh') {
                    $active = Attempt::where('user_id', $user->id)->where('mode', 'fresh')->where('status', 'active')->latest('id')->first();
                    if ($active) {
                        return $active;
                    }
                    $user->update(['fresh_subjects' => $settings['subjects'], 'fresh_unseen' => $settings['fresh_unseen'] ?? 7, 'fresh_balance' => $settings['fresh_balance'] ?? true]);
                    $settings['count'] = 10;
                    $settings['balance'] = false;
                    $settings['topics'] = [];
                    $settings['difficulty'] = 'All';
                }
                $items = $mode === 'fresh' ? app(FreshCatch::class)->select($user->id, $settings) : $this->draw->select($this->eligible($user->id, $settings), $settings);
                $items = $this->catalog->hydrate($items);
                if ($mode === 'fresh') {
                    $settings['fresh_actual'] = collect($items)->countBy('fresh_kind')->all();
                }
                $deadline = $mode === 'exam' ? now()->addMinutes($settings['minutes']) : null;
            }
            $attempt = Attempt::create(['user_id' => $user->id, 'assignment_id' => $assignment?->id, 'mode' => $mode, 'settings' => $settings, 'deadline' => $deadline]);
            foreach ($items as $i => $q) {
                $attempt->items()->create(['question_id' => $q['id'], 'position' => $i, 'snapshot' => $q]);
            }
            $user->update(['last_active_at' => now()]);

            return $attempt;
        }, 3);
    }

    private function grade(Attempt $attempt, AttemptItem $item): void
    {
        if ($item->graded_at) {
            return;
        }
        $correct = $item->answer !== null && $item->answer === $item->snapshot['key'];
        $item->update(['correct' => $correct, 'graded_at' => now()]);
        if ($item->answer !== null) {
            app(Learning::class)->answer($attempt->user_id, 'attempt:'.$item->id, $item->snapshot, $attempt->mode, $correct);
            $p = Progress::firstOrCreate(['user_id' => $attempt->user_id, 'question_id' => $item->question_id]);
            $p->update(['selected_at' => $p->selected_at ?? now(), 'completed_at' => now(), 'latest_answer' => $item->answer, 'latest_correct' => $correct, 'legacy' => false]);
        }
    }

    public function finish(Attempt $attempt, string $status = 'finished'): Attempt
    {
        return DB::transaction(function () use ($attempt, $status) {
            User::whereKey($attempt->user_id)->lockForUpdate()->firstOrFail();
            $a = Attempt::lockForUpdate()->findOrFail($attempt->id);
            if ($a->status !== 'active') {
                return $a;
            }
            if ($a->mode === 'fresh' && $a->items()->whereNull('answer')->exists()) {
                throw ValidationException::withMessages(['answers' => 'Answer all '.$a->items()->count().' questions to finish your Fresh Catch.']);
            }
            foreach ($a->items as $item) {
                $this->grade($a, $item);
            }
            $a->update(['status' => $status, 'finished_at' => now(), 'score' => $a->items()->where('correct', true)->count(), 'revision' => $a->revision + 1]);
            app(Learning::class)->completed($a);

            return $a->refresh();
        }, 3);
    }

    public function expire(Attempt $attempt): Attempt
    {
        if ($attempt->status === 'active' && $attempt->deadline && now()->gte($attempt->deadline)) {
            return $this->finish($attempt, 'timed_out');
        }

        return $attempt;
    }

    public function answer(Attempt $attempt, int $itemId, array $data): Attempt
    {
        // Expiry is persisted before reporting the conflict, not rolled back with it.
        $attempt = $this->expire($attempt);

        return DB::transaction(function () use ($attempt, $itemId, $data) {
            User::whereKey($attempt->user_id)->lockForUpdate()->firstOrFail();
            $a = Attempt::lockForUpdate()->findOrFail($attempt->id);
            $fingerprint = hash('sha256', json_encode([$a->id, $itemId, $data['answer'], (bool) ($data['check'] ?? false), (int) $data['revision']]));
            if (! empty($data['request_token'])) {
                $receipt = DB::table('study_answer_receipts')->where('user_id', $a->user_id)->where('request_token', $data['request_token'])->first();
                if ($receipt) {
                    abort_unless(hash_equals($receipt->fingerprint, $fingerprint), 409, 'This save token belongs to another answer. Reload before continuing.');

                    return $a;
                }
            }
            abort_if($a->status !== 'active' || ($a->deadline && now()->gte($a->deadline)), 409, 'This attempt has ended. Reload to view results.');
            $item = $a->items()->findOrFail($itemId);
            if ($item->graded_at && $item->answer === $data['answer']) {
                return $a;
            }
            abort_if($item->graded_at, 409, 'This checked answer is locked.');
            abort_if($a->revision !== (int) $data['revision'], 409, 'A newer answer was saved on another page. Reload before continuing.');
            if (! empty($data['check']) && in_array($a->mode, ['exam', 'assigned'])) {
                abort(422, 'Feedback is available after submission.');
            }
            if ($item->answer !== $data['answer']) {
                $item->update(['answer' => $data['answer']]);
                DB::table('answer_revisions')->insert(['attempt_item_id' => $item->id, 'answer' => $item->answer, 'revision' => $a->revision + 1, 'created_at' => now(), 'updated_at' => now()]);
            }
            $p = Progress::firstOrCreate(['user_id' => $a->user_id, 'question_id' => $item->question_id]);
            if (! $p->selected_at) {
                $p->update(['selected_at' => now()]);
            }
            if (! empty($data['check'])) {
                $this->grade($a, $item);
                if ($a->mode === 'tutor') {
                    app(Learning::class)->view($a->user_id, $item->snapshot, 'tutor');
                }
            }
            $a->update(['revision' => $a->revision + 1]);
            if (! empty($data['request_token'])) {
                DB::table('study_answer_receipts')->insert(['user_id' => $a->user_id, 'request_token' => $data['request_token'], 'fingerprint' => $fingerprint, 'created_at' => now()]);
            }
            User::whereKey($a->user_id)->update(['last_active_at' => now()]);

            return $a->refresh();
        }, 3);
    }

    public function present(Attempt $a): array
    {
        $a = $this->expire($a);
        $release = $a->mode !== 'assigned' || (bool) $a->assignment?->release_answers;
        $finished = $a->status !== 'active';

        return ['id' => $a->id, 'mode' => $a->mode, 'status' => $a->status, 'revision' => $a->revision, 'deadline' => $a->deadline?->toIso8601String(),
            'server_now' => now()->toIso8601String(), 'score' => $finished ? $a->score : null, 'active_seconds' => $a->active_seconds,
            'items' => $a->items()->get()->map(function ($item) use ($release, $finished, $a) {
                $feedback = $release && ($finished || ($item->graded_at && ! in_array($a->mode, ['exam', 'assigned'])));

                return ['item_id' => $item->id, 'answer' => $item->answer, 'checked' => (bool) $item->graded_at, 'correct' => $feedback ? $item->correct : null, ...Catalog::publicQuestion($item->snapshot, $feedback)];
            })->all()];
    }
}
