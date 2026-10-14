<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Notebook
{
    public function rows(int $userId): Collection
    {
        $events = app(StudyGuide::class)->events($userId);
        $latest = $events->keyBy('question_hash');
        $missed = $events->where('correct', false)->keyBy('question_hash');
        $notes = DB::table('study_notes')->where('user_id', $userId)->get()->keyBy('question_id');

        return collect(app(Catalog::class)->items())->unique(fn ($q) => Catalog::stemHash($q['stem']))
            ->filter(fn ($q) => $missed->has(Catalog::stemHash($q['stem'])) || $notes->has($q['id']))
            ->map(function ($q) use ($latest, $notes) {
                $event = $latest->get(Catalog::stemHash($q['stem']));

                return [...Catalog::publicQuestion($q, true), 'latest_correct' => $event ? (bool) $event->correct : null,
                    'last_answered' => $event?->occurred_at, 'reason' => $notes->get($q['id'])?->reason,
                    'note' => $notes->get($q['id'])?->note, 'note_revision' => $notes->get($q['id'])?->revision ?? 0];
            })->sortByDesc('last_answered')->values();
    }
}
