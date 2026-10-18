<?php

namespace App\Services;

class Taxonomy
{
    public static function data(): array
    {
        static $data;
        if ($data === null) {
            $data = json_decode(file_get_contents(__DIR__.'/../../database/data/v3/taxonomy.json'), true, 512, JSON_THROW_ON_ERROR);
            foreach (['subjects', 'topics', 'subtopics'] as $table) {
                $data[$table] = array_column($data[$table], null, 'code');
            }
        }

        return $data;
    }

    public static function mappings(): array
    {
        static $mappings;

        return $mappings ??= json_decode(file_get_contents(__DIR__.'/../../database/data/v3/retag.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public static function matches(array $q, string $selection): bool
    {
        return in_array($selection, [$q['topic_code'] ?? null, $q['subject']."\x1f".$q['topic'],
            (isset($q['id'], self::mappings()[$q['id']]) ? self::mappings()[$q['id']]['legacy_subject']."\x1f".self::mappings()[$q['id']]['legacy_topic'] : null)], true);
    }

    public static function matchesAny(array $q, array $selections): bool
    {
        return ! $selections || (bool) array_filter($selections, fn ($value) => self::matches($q, $value));
    }
}
