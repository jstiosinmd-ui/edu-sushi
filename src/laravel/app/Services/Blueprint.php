<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class Blueprint
{
    /** Match quota slots to distinct stems, including overlapping rows and cross-subject duplicates. */
    public function select(array $pool, array $rows, int $count): array
    {
        if (array_sum(array_column($rows, 'count')) !== $count) {
            throw ValidationException::withMessages(['blueprint' => 'Blueprint row counts must add up to '.$count.'.']);
        }
        $choices = [];
        foreach ($rows as $i => $row) {
            $choices[$i] = [];
            foreach ($pool as $q) {
                if ($q['subject'] === $row['subject'] && (empty($row['topic']) || $q['topic'] === $row['topic'])
                    && ($row['difficulty'] === 'All' || $q['tier'] === $row['difficulty'])) {
                    $choices[$i][Catalog::stemHash($q['stem'])] ??= $q;
                }
            }
            if (count($choices[$i]) < $row['count']) {
                throw ValidationException::withMessages(['blueprint' => 'Row '.($i + 1).' has only '.count($choices[$i]).' distinct questions with your filters.']);
            }
        }
        $slots = [];
        foreach ($rows as $i => $row) {
            for ($n = 0; $n < $row['count']; $n++) {
                $slots[] = $i;
            }
        }
        // Constrained slots first; augmenting paths recover feasible overlapping allocations.
        usort($slots, fn ($a, $b) => count($choices[$a]) <=> count($choices[$b]));
        $owners = [];
        $assigned = [];
        $place = function ($slot, &$seen) use (&$place, &$owners, &$assigned, $choices, $slots): bool {
            foreach ($choices[$slots[$slot]] as $hash => $q) {
                if (! isset($owners[$hash])) {
                    $owners[$hash] = $slot;
                    $assigned[$slot] = $hash;

                    return true;
                }
            }
            foreach ($choices[$slots[$slot]] as $hash => $q) {
                if (isset($seen[$hash])) {
                    continue;
                }
                $seen[$hash] = true;
                if ($place($owners[$hash], $seen)) {
                    $owners[$hash] = $slot;
                    $assigned[$slot] = $hash;

                    return true;
                }
            }

            return false;
        };
        foreach ($slots as $slot => $row) {
            $seen = [];
            if (! $place($slot, $seen)) {
                throw ValidationException::withMessages(['blueprint' => 'These quota rows overlap more than the distinct pool allows. Adjust counts or broaden coverage.']);
            }
        }

        return array_map(fn ($slot) => $choices[$slots[$slot]][$assigned[$slot]], array_keys($slots));
    }
}
