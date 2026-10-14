<?php

namespace App\Services;

use App\Models\Question;
use Illuminate\Support\Facades\DB;

class QuestionNotices
{
    public function correction(Question $question): void
    {
        $ids = DB::table('study_events')->where('question_id', $question->id)->pluck('user_id')
            ->merge(DB::table('attempt_items')->join('attempts', 'attempts.id', '=', 'attempt_items.attempt_id')->where('question_id', $question->id)->pluck('attempts.user_id'))
            ->merge(DB::table('flashcard_deck_items')->join('flashcard_decks', 'flashcard_decks.id', '=', 'flashcard_deck_items.flashcard_deck_id')->where('question_id', $question->id)->pluck('flashcard_decks.user_id'))
            ->merge(DB::table('question_reports')->where('question_id', $question->id)->pluck('user_id'))->unique();
        $version = $question->currentVersion->version;
        foreach ($ids as $id) {
            $this->send($id, 'version:'.$question->current_version_id, $question->id, 'A question you studied was updated',
                $question->id.' now has version '.$version.'. New rounds use the updated question. Saved exams, attempts, and existing decks retain their original version.');
        }
    }

    public function send(int $userId, string $event, string $questionId, string $title, string $message): void
    {
        DB::table('learning_notices')->insertOrIgnore(['user_id' => $userId, 'event_key' => $event, 'question_id' => $questionId,
            'title' => $title, 'message' => $message, 'created_at' => now()]);
    }
}
