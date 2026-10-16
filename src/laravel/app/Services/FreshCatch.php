<?php

namespace App\Services;

use App\Models\Progress;
use App\Models\Question;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class FreshCatch
{
    public function eligible(int $userId): array
    {
        $results = [];
        $progress = Progress::where('user_id', $userId)->whereNotNull('completed_at')->get()->keyBy('question_id');
        foreach (Question::all(['id', 'stem_hash']) as $q) {
            $p = $progress->get($q->id);
            if ($p && (! isset($results[$q->stem_hash]) || $results[$q->stem_hash]['at'] < $p->completed_at)) {
                $results[$q->stem_hash] = ['at' => $p->completed_at, 'correct' => $p->latest_correct];
            }
        }
        foreach (app(StudyGuide::class)->events($userId) as $event) {
            $at = Carbon::parse($event->occurred_at);
            if (! isset($results[$event->question_hash]) || $results[$event->question_hash]['at'] <= $at) {
                $results[$event->question_hash] = ['at' => $at, 'correct' => $event->correct];
            }
        }
        $out = [];
        foreach (app(Catalog::class)->items() as $q) {
            $last = $results[Catalog::stemHash($q['stem'])] ?? null;
            if (! ($last['correct'] ?? false)) {
                $out[] = [...$q, 'fresh_kind' => $last ? 'missed' : 'unseen'];
            }
        }

        return $out;
    }

    public function select(int $userId, array $settings): array
    {
        $pool = collect($this->eligible($userId))->whereIn('subject', $settings['subjects'])
            ->sortBy(fn ($q) => hash('sha256', $settings['seed'].':'.$q['id']))->unique(fn ($q) => Catalog::stemHash($q['stem']))->values();
        if ($pool->count() < 10) {
            throw ValidationException::withMessages(['count' => 'Only '.$pool->count().' unseen or previously missed questions remain. Choose more subjects.']);
        }
        $unseen = $pool->where('fresh_kind', 'unseen')->count();
        $missed = $pool->count() - $unseen;
        $target = min($unseen, max((int) ($settings['fresh_unseen'] ?? 7), 10 - $missed));
        $needs = ['unseen' => $target, 'missed' => 10 - $target];
        $chosen = [];
        $coverage = [];
        // Choose the scarcer requested category first, then favor underrepresented subjects.
        $kinds = $unseen < $missed ? ['unseen', 'missed'] : ['missed', 'unseen'];
        foreach ($kinds as $kind) {
            $candidates = $pool->where('fresh_kind', $kind)->values()->all();
            for ($i = 0; $i < $needs[$kind]; $i++) {
                if ($settings['fresh_balance'] ?? true) {
                    usort($candidates, fn ($a, $b) => ($coverage[$a['subject']] ?? 0) <=> ($coverage[$b['subject']] ?? 0));
                }
                $q = array_shift($candidates);
                $chosen[] = $q;
                $coverage[$q['subject']] = ($coverage[$q['subject']] ?? 0) + 1;
            }
        }

        return collect($chosen)->sortBy(fn ($q) => hash('sha256', $settings['seed'].':order:'.$q['id']))->values()->all();
    }
}
