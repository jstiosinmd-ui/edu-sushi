<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Notebook
{
    public function rows(int $userId, bool $withContent = true): Collection
    {
        $events = app(StudyGuide::class)->events($userId);
        $latest = $events->keyBy('question_hash');
        $missed = $events->where('correct', false)->keyBy('question_hash');
        $notes = DB::table('study_notes')->where('user_id', $userId)->get()->keyBy('question_id');

        $rows = collect(app(Catalog::class)->candidates())->unique(fn ($q) => Catalog::questionHash($q))
            ->filter(fn ($q) => $missed->has(Catalog::questionHash($q)) || $notes->has($q['id']))
            ->map(function ($q) use ($latest, $notes) {
                $event = $latest->get(Catalog::questionHash($q));

                return [...$q, 'latest_correct' => $event ? (bool) $event->correct : null,
                    'last_answered' => $event?->occurred_at, 'reason' => $notes->get($q['id'])?->reason,
                    'note' => $notes->get($q['id'])?->note, 'note_revision' => $notes->get($q['id'])?->revision ?? 0];
            })->sortByDesc('last_answered')->values();
        if (! $withContent) {
            return $rows;
        }
        $content = collect(app(Catalog::class)->hydrate($rows->all()))->keyBy('id');

        return $rows->map(fn ($q) => [...$q, ...Catalog::publicQuestion($content[$q['id']], true)]);
    }
}
