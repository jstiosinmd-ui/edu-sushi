<?php

namespace App\Services;

use App\Models\Attempt;
use App\Models\Progress;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class Learning
{
    public function answer(int $userId, string $source, array $question, string $mode, bool $correct, $at = null, ?bool $bookmarked = null): void
    {
        DB::table('study_events')->insertOrIgnore(['user_id' => $userId, 'source' => $source, 'question_id' => $question['id'],
            'question_hash' => Catalog::stemHash($question['stem']), 'subject' => $question['subject'], 'mode' => $mode, 'correct' => $correct,
            'bookmarked' => $bookmarked ?? Progress::where('user_id', $userId)->where('question_id', $question['id'])->where('bookmarked', true)->exists(),
            'occurred_at' => $at ?? now()]);
    }

    public function view(int $userId, array $q, string $mode): void
    {
        DB::table('learning_views')->insertOrIgnore(['user_id' => $userId, 'question_hash' => Catalog::stemHash($q['stem']), 'subject' => $q['subject'], 'mode' => $mode, 'created_at' => now()]);
    }

    public function completed(Attempt $attempt): void
    {
        if ($attempt->mode !== 'fresh' || $attempt->status !== 'finished' || $attempt->items()->count() !== 10 || $attempt->items()->whereNull('answer')->exists()) {
            return;
        }
        $u = User::findOrFail($attempt->user_id);
        $at = $attempt->finished_at;
        $day = $at->copy()->timezone($u->timezone)->toDateString();
        DB::table('daily_catches')->insertOrIgnore(['user_id' => $u->id, 'attempt_id' => $attempt->id, 'day' => $day, 'timezone' => $u->timezone, 'completed_at' => $at]);
        DB::table('catch_reminders')->where('user_id', $u->id)->where('day', $day)->where('status', 'queued')->update(['status' => 'cancelled', 'updated_at' => now()]);
    }
}
