<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Validation\ValidationException;

class Selection
{
    public function pool(User $user, array $s): array
    {
        $rows = app(Study::class)->eligible($user->id, $s);

        return array_values(array_filter($rows, fn ($q) => in_array($q['subject'], $s['subjects'], true)
            && (empty($s['topics']) || in_array($q['subject']."\x1f".$q['topic'], $s['topics'], true))
            && (($s['difficulty'] ?? 'All') === 'All' || $s['difficulty'] === $q['tier'])));
    }

    public function exact(array $ids): array
    {
        $items = array_values(array_filter(app(Catalog::class)->items(), fn ($q) => in_array($q['id'], $ids, true)));
        if (count($items) !== count($ids)) {
            throw ValidationException::withMessages(['selected_ids' => 'Some questions are no longer available. Update your tray.']);
        }
        if (count(array_unique(array_map(fn ($q) => Catalog::stemHash($q['stem']), $items))) !== count($items)) {
            throw ValidationException::withMessages(['selected_ids' => 'Two selected items have the same question. Keep one copy.']);
        }

        return $items;
    }
}
