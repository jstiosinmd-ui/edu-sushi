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
            && Taxonomy::matchesAny($q, $s['topics'] ?? [])
            && (($s['difficulty'] ?? 'All') === 'All' || $q['tier'] === $s['difficulty'])));
        if (! empty($s['blueprint'])) {
            return $this->shuffle(app(Blueprint::class)->select($this->shuffle($pool, $rng), $s['blueprint'], (int) $s['count']), $rng);
        }
        $seen = [];
        $eligible = [];
        foreach ($this->shuffle($pool, $rng) as $q) {
            $h = Catalog::questionHash($q);
            if (! isset($seen[$h])) {
                $seen[$h] = true;
                $eligible[] = $q;
            }
        }
        $count = (int) $s['count'];
        if ($count > count($eligible)) {
            throw ValidationException::withMessages(['count' => 'Only '.count($eligible).' distinct questions match. Broaden your filters or reduce the count. Fresh Catch requires exactly 10.']);
        }
        if (! empty($s['allocation'])) {
            $allocation = $s['allocation'];
            if (array_diff(array_keys($allocation), $s['subjects']) || array_sum($allocation) !== $count) {
                throw ValidationException::withMessages(['allocation' => 'Subject counts must add up to the total and use selected subjects.']);
            }
            $chosen = [];
            foreach ($s['subjects'] as $subject) {
                $n = (int) ($allocation[$subject] ?? 0);
                $bucket = array_values(array_filter($eligible, fn ($q) => $q['subject'] === $subject));
                if ($n > count($bucket)) {
                    throw ValidationException::withMessages(['allocation' => 'Only '.count($bucket).' questions match in '.$subject.'.']);
                }
                $chosen = array_merge($chosen, array_slice($bucket, 0, $n));
            }

            return $this->shuffle($chosen, $rng);
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
            $b = array_values(array_filter($eligible, fn ($q) => $topics ? Taxonomy::matches($q, $group) : $q['subject'] === $group));
            if (! $b) {
                throw ValidationException::withMessages(['subjects' => 'No matching questions for '.$group.'.']);
            }
            $buckets[] = $b;
        }
        $chosen = [];
        $chosenHashes = [];
        while (count($chosen) < $count) {
            foreach ($buckets as &$b) {
                while ($b && count($chosen) < $count) {
                    $next = array_pop($b);
                    $hash = Catalog::questionHash($next);
                    if (! isset($chosenHashes[$hash])) {
                        $chosen[] = $next;
                        $chosenHashes[$hash] = true;
                        break;
                    }
                }
            }
        }

        return $this->shuffle($chosen, $rng);
    }
}
