<?php

namespace App\Services;

class WeightedDeck
{
    // Relative draw weights, not waiting periods. A rating-1 card has six times
    // the probability of a rating-5 card, all else equal. Unrated cards get 6.
    public const WEIGHTS = [0 => 6, 1 => 6, 2 => 4, 3 => 3, 4 => 2, 5 => 1];

    public function choose(array $items, ?int $ticket = null, array $recent = []): string
    {
        // Leave three intervening cards when the deck permits it. Small decks
        // shorten this gap, and a single-card deck remains usable.
        $gap = min(3, max(0, count($items) - 1));
        $excluded = $gap ? array_slice($recent, -$gap) : [];
        $items = array_values(array_filter($items, fn ($q) => ! in_array($q['question_id'], $excluded, true)));
        $total = array_sum(array_map(fn ($q) => self::WEIGHTS[$q['confidence'] ?? 0], $items));
        if (! $total) {
            throw new \InvalidArgumentException('A deck must contain questions.');
        }
        $ticket ??= random_int(1, $total);
        if ($ticket < 1 || $ticket > $total) {
            throw new \InvalidArgumentException('Invalid draw ticket.');
        }
        foreach ($items as $q) {
            $ticket -= self::WEIGHTS[$q['confidence'] ?? 0];
            if ($ticket <= 0) {
                return $q['question_id'];
            }
        }
        throw new \LogicException('Deck draw failed.');
    }
}
