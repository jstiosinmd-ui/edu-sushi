<?php

namespace App\Services;

use App\Models\Assignment;
use App\Models\Attempt;
use App\Models\AttemptItem;
use App\Models\Progress;
use App\Models\Question;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Study
{
    public function __construct(private Catalog $catalog, private Draw $draw) {}

    public function eligible(int $userId, array $settings): array
    {
        $items = $this->catalog->items();
        $progress = Progress::where('user_id', $userId)->get()->keyBy('question_id');
        if (($settings['mode'] ?? '') === 'fresh') {
            // The latest graded result across a duplicate-stem group determines retry eligibility.
            $results = [];
            foreach (Question::all() as $q) {
                $p = $progress[$q->id] ?? null;
                if ($p?->completed_at && (! isset($results[$q->stem_hash]) || $results[$q->stem_hash]['at'] < $p->completed_at)) {
                    $results[$q->stem_hash] = ['at' => $p->completed_at, 'correct' => $p->latest_correct];
                }
            }

            return array_values(array_filter($items, fn ($q) => ! ($results[Catalog::stemHash($q['stem'])]['correct'] ?? false)));
        }

        return array_values(array_filter($items, function ($q) use ($progress, $settings) {
            $p = $progress[$q['id']] ?? null;

            return match ($settings['pool'] ?? 'all') {
                'new' => ! $p?->completed_at, 'missed' => $p?->completed_at && ! $p->latest_correct,
                'bookmarks' => (bool) $p?->bookmarked, default => true
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
                    $settings['count'] = 10;
                    $settings['balance'] = false;
                    $settings['topics'] = [];
                    $settings['difficulty'] = 'All';
                }
                $items = $this->draw->select($this->eligible($user->id, $settings), $settings);
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
            $p = Progress::firstOrCreate(['user_id' => $attempt->user_id, 'question_id' => $item->question_id]);
            $p->update(['selected_at' => $p->selected_at ?? now(), 'completed_at' => now(), 'latest_answer' => $item->answer, 'latest_correct' => $correct, 'legacy' => false]);
        }
    }

    public function finish(Attempt $attempt, string $status = 'finished'): Attempt
    {
        return DB::transaction(function () use ($attempt, $status) {
            $a = Attempt::lockForUpdate()->findOrFail($attempt->id);
            if ($a->status !== 'active') {
                return $a;
            }
            foreach ($a->items as $item) {
                $this->grade($a, $item);
            }
            $a->update(['status' => $status, 'finished_at' => now(), 'score' => $a->items()->where('correct', true)->count(), 'revision' => $a->revision + 1]);

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
            $a = Attempt::lockForUpdate()->findOrFail($attempt->id);
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
            }
            $a->update(['revision' => $a->revision + 1]);
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
