<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class Leaderboard
{
    public function for(User $viewer, string $view = 'weekly'): array
    {
        $start = CarbonImmutable::now('Asia/Manila')->startOfWeek();
        $end = $start->addWeek();
        $rows = User::where(fn ($q) => $q->where('role', 'student')->orWhere('leaderboard_enabled', true))
            ->where('disabled', false)->where('invitation_pending', false)->whereNull('archived_at')->orderBy('id')->get()->map(function ($u) use ($start, $end, $view) {
                $events = DB::table('study_events')->where('user_id', $u->id)->where('mode', '!=', 'assigned');
                $all = (clone $events)->distinct()->count('question_hash');
                $weekly = (clone $events)->where('occurred_at', '>=', $start->utc())->where('occurred_at', '<', $end->utc())->orderBy('occurred_at')->orderBy('id')->get()->unique('question_hash');
                $days = DB::table('daily_catches')->where('user_id', $u->id)->where('completed_at', '>=', $start->utc())->where('completed_at', '<', $end->utc())->get()->unique(fn ($r) => CarbonImmutable::parse($r->completed_at, 'UTC')->timezone('Asia/Manila')->toDateString())->count();
                $points = $weekly->count() + $weekly->where('correct', true)->count() + 5 * $days;
                $habit = app(Habits::class)->for($u);

                return ['profile' => Closet::profile($u), 'tier' => Achievements::for($u->id)['name'], 'points' => $points, 'coverage' => $all, 'streak' => $habit['current'], 'longest' => $habit['longest'],
                    'value' => match ($view) {
                        'coverage' => $all,'streak' => $habit['current'],default => $points
                    }];
            })->sortByDesc('value')->values();
        $previous = null;
        $rank = 0;
        $rows = $rows->map(function ($r, $i) use (&$previous, &$rank, $viewer) {
            if ($r['value'] !== $previous) {
                $rank = $i + 1;
            }
            $previous = $r['value'];

            return [...$r, 'rank' => $rank, 'is_you' => $r['profile']['id'] === $viewer->id];
        });

        return ['view' => $view, 'rows' => $rows, 'starts' => $start->toDateString(), 'ends' => $end->subDay()->toDateString(), 'timezone' => 'Asia/Manila',
            'my_rank' => $rows->firstWhere('is_you', true)['rank'] ?? null];
    }
}
