<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class Habits
{
    public function for(User $u): array
    {
        $today = CarbonImmutable::now($u->timezone)->toDateString();
        $rows = DB::table('daily_catches')->where('user_id', $u->id)->where('day', '<=', $today)->orderBy('day')->get()->keyBy('day');
        $current = $longest = $saves = $total = $comeback = 0;
        $states = [];
        $previous = null;
        $weeks = [];
        if ($rows->isNotEmpty()) {
            // Dates are immutable recorded local dates, unaffected by later timezone changes.
            $d = CarbonImmutable::parse($rows->keys()->first(), 'UTC');
            $end = CarbonImmutable::parse($today, 'UTC');
            for (; $d->lte($end); $d = $d->addDay()) {
                $day = $d->toDateString();
                if ($rows->has($day)) {
                    $total++;
                    $current++;
                    $longest = max($longest, $current);
                    if ($total % 7 === 0) {
                        $saves = min(2, $saves + 1);
                    }
                    if ($previous && $previous->diffInDays($d) >= 4) {
                        $comeback = 1;
                    }
                    $previous = $d;
                    $states[$day] = 'completed';
                    $week = $d->startOfWeek()->toDateString();
                    $weeks[$week] = ($weeks[$week] ?? 0) + 1;
                } elseif ($day !== $today) {
                    if ($saves > 0 && $current > 0) {
                        $saves--;
                        $states[$day] = 'protected';
                    } else {
                        $current = 0;
                        $states[$day] = 'missed';
                    }
                }
            }
        }
        $start = CarbonImmutable::parse($today, 'UTC')->startOfWeek();
        $calendar = [];
        for ($i = 0; $i < 7; $i++) {
            $d = $start->addDays($i);
            $date = $d->toDateString();
            $calendar[] = ['day' => $date, 'label' => $d->format('D'), 'state' => $states[$date] ?? ($date > $today ? 'future' : ($date === $today ? 'today' : 'missed')), 'today' => $date === $today];
        }

        return ['today' => $today, 'completed_today' => $rows->has($today), 'current' => $current, 'longest' => $longest, 'saves' => $saves,
            'total_days' => $total, 'week_days' => $weeks[$start->toDateString()] ?? 0, 'best_week' => $weeks ? max($weeks) : 0,
            'comeback' => $comeback, 'calendar' => $calendar, 'timezone' => $u->timezone];
    }
}
