<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class Draw
{
    private static function multiply(int $a, int $b): int
    {
        // Low 32-bit product without overflowing a PHP signed 64-bit integer.
        return (($a & 65535) * $b + (((($a >> 16) * $b) & 65535) << 16)) & 0xFFFFFFFF;
    }

    private function random(int $seed): \Closure
    {
        $a = $seed;

        return function () use (&$a) {
            $a = ($a + 0x6D2B79F5) & 0xFFFFFFFF;
            $t = self::multiply($a ^ ($a >> 15), $a | 1);
            $t = ($t ^ (($t + self::multiply($t ^ ($t >> 7), $t | 61)) & 0xFFFFFFFF)) & 0xFFFFFFFF;

            return (($t ^ ($t >> 14)) & 0xFFFFFFFF) / 4294967296;
        };
    }

    private function shuffle(array $items, \Closure $rng): array
    {
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = (int) floor($rng() * ($i + 1));
            [$items[$i],$items[$j]] = [$items[$j], $items[$i]];
        }

        return $items;
    }

    public function select(array $items, array $s): array
    {
        $rng = $this->random((int) $s['seed']);
        $pool = array_values(array_filter($items, fn ($q) => in_array($q['subject'], $s['subjects'], true)
            && (empty($s['topics']) || in_array($q['subject']."\x1f".$q['topic'], $s['topics'], true))
            && (($s['difficulty'] ?? 'All') === 'All' || $q['tier'] === $s['difficulty'])));
        $seen = [];
        $eligible = [];
        foreach ($this->shuffle($pool, $rng) as $q) {
            $h = Catalog::stemHash($q['stem']);
            if (! isset($seen[$h])) {
                $seen[$h] = true;
                $eligible[] = $q;
            }
        }
        $count = (int) $s['count'];
        if ($count > count($eligible)) {
            throw ValidationException::withMessages(['count' => 'Only '.count($eligible).' distinct questions match. Broaden your filters or reduce the count. Fresh Catch requires exactly 10.']);
        }
        if (empty($s['balance'])) {
            return array_slice($eligible, 0, $count);
        }
        $topics = ! empty($s['topics']);
        $groups = $topics ? $s['topics'] : $s['subjects'];
        if ($count < count($groups)) {
            throw ValidationException::withMessages(['count' => 'Choose at least '.count($groups).' questions for balanced coverage.']);
        }
        $buckets = [];
        foreach ($groups as $group) {
            $b = array_values(array_filter($eligible, fn ($q) => ($topics ? $q['subject']."\x1f".$q['topic'] : $q['subject']) === $group));
            if (! $b) {
                throw ValidationException::withMessages(['subjects' => 'No matching questions for '.$group.'.']);
            }
            $buckets[] = $b;
        }
        $chosen = [];
        while (count($chosen) < $count) {
            foreach ($buckets as &$b) {
                if ($b && count($chosen) < $count) {
                    $chosen[] = array_pop($b);
                }
            }
        }

        return $this->shuffle($chosen, $rng);
    }
}
