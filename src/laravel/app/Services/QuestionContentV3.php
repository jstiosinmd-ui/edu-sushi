<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QuestionContentV3
{
    public static function clinicalHash(array $q): string
    {
        $clinical = [];
        foreach (['id', 'subject', 'tier', 'stem', 'options', 'key', 'rationale', 'expl'] as $key) {
            $clinical[$key] = $q[$key];
        }

        return hash('sha256', json_encode($clinical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function convert(array $q, array $mapping, bool $active): array
    {
        $raw = $q['raw'];
        $q['schema_version'] = 3;
        $q['subject_code'] = $mapping['subject_code'];
        $q['subject'] = Taxonomy::data()['subjects'][$q['subject_code']]['name'];
        $q['topic_code'] = $mapping['topic_code'];
        $q['topic'] = Taxonomy::data()['topics'][$q['topic_code']]['name'];
        $q['subtopic_code'] = $mapping['subtopic_code'];
        $q['cognitive'] = $raw['Cognitive Category'];
        $q['qtype'] = $raw['Question Type'];
        $q['objective'] = $raw['Learning Objective'];
        $urls = array_map(fn ($url) => filter_var(trim($url), FILTER_VALIDATE_URL) ? trim($url) : null, explode(';', $raw['Source URLs']));
        $ids = array_values(array_unique(array_filter(array_map('trim', explode(';', $raw['Reference IDs'])))));
        $q['references'] = array_map(function ($id, $i) use ($raw, $urls, $ids) {
            return [
                'authority_id' => strlen($id) <= 80 ? $id : 'LEGACY-'.hash('sha256', $id),
                'citation' => $raw['Answer Source'],
                // Preserve the exact source citation. Do not invent a page locator.
                'locator' => $raw['Answer Source'],
                'url' => count($urls) === count($ids) ? $urls[$i] : (count($ids) === 1 ? ($urls[0] ?? null) : null),
                'accessed' => null, 'supports' => 'key', 'verification_status' => 'legacy_unverified',
            ];
        }, $ids, array_keys($ids));
        $q['lineage'] = [
            'origin' => $mapping['origin'],
            'source_groups' => [], 'source_locators' => array_values(array_filter([$raw['Source Locator']])),
            // Legacy recurrence counts files. Distinct exam families remain unknown.
            'recurrence' => (int) $raw['Recurrence Count'], 'course_tag' => $raw['Course Tag'],
            'source_key_check' => $raw['Source Key Check'], 'conversion_note' => $raw['Conversion Note'],
        ];
        $q['media'] = [];
        $q['review'] = ['status' => $active ? 'Active' : 'Retired', 'notes' => $raw['Review Notes'],
            'source_review' => null, 'cue_review' => null, 'clinical_review' => null];
        $q['status'] = $q['review']['status'];
        $q['raw']['Review Status'] = $q['status'];
        // Keep the original 33-column raw row, including its legacy topic.
        $q['raw'] += ['Topic Code' => $q['topic_code'], 'Subtopic Code' => $q['subtopic_code'] ?? '',
            'Subtopic' => $q['subtopic_code'] ? Taxonomy::data()['subtopics'][$q['subtopic_code']]['name'] : '',
            'Concept Key' => '', 'Origin' => $q['lineage']['origin'], 'Source Group IDs' => ''];

        return $q;
    }

    public function validate(array $q, bool $registered = true): void
    {
        static $schema;
        $schema ??= json_decode(file_get_contents(database_path('data/v3/question-content.schema.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->check($q, $schema, $schema, 'content');
        $tax = Taxonomy::data();
        if (($tax['subjects'][$q['subject_code']]['name'] ?? null) !== $q['subject']
            || ($tax['topics'][$q['topic_code']]['subject_code'] ?? null) !== $q['subject_code']
            || ($tax['topics'][$q['topic_code']]['name'] ?? null) !== $q['topic']
            || (! empty($q['subtopic_code']) && ($tax['subtopics'][$q['subtopic_code']]['topic_code'] ?? null) !== $q['topic_code'])
            || (! empty($q['concept_key']) && empty($q['subtopic_code']))) {
            $this->invalid('taxonomy', 'Use a matching subject, topic and subtopic from the controlled taxonomy.');
        }
        if (isset($q['status']) && $q['status'] !== $q['review']['status']) {
            $this->invalid('status', 'Status must agree with review.status.');
        }
        foreach ($registered ? $q['references'] : [] as $ref) {
            if (! DB::table('authorities')->where('id', $ref['authority_id'])->exists()) {
                $this->invalid('references', 'Register authority '.$ref['authority_id'].' before import.');
            }
        }
        foreach ($registered ? ($q['lineage']['source_groups'] ?? []) : [] as $id) {
            $group = DB::table('source_groups')->where('id', $id)->first();
            if (! $group || ($q['lineage']['origin'] !== 'authority_authored' && $group->origin !== 'school')) {
                $this->invalid('lineage', 'Conversions must link to a school source group.');
            }
        }
        foreach ($registered ? ($q['media'] ?? []) : [] as $media) {
            if (! DB::table('question_media')->where('id', $media['asset_id'])->where('question_id', $q['id'])->exists()) {
                $this->invalid('media', 'Register the media asset for this question before import.');
            }
        }
    }

    private function check(mixed $value, array $rule, array $schema, string $path): void
    {
        if (isset($rule['$ref'])) {
            $rule = $schema['$defs'][substr($rule['$ref'], strlen('#/$defs/'))];
        }
        if (isset($rule['type'])) {
            $valid = false;
            foreach ((array) $rule['type'] as $type) {
                $valid = $valid || match ($type) {
                    'object' => is_array($value) && (! array_is_list($value) || $value === []),
                    'array' => is_array($value) && array_is_list($value),
                    'string' => is_string($value), 'integer' => is_int($value),
                    'boolean' => is_bool($value), 'null' => $value === null,
                    default => false,
                };
            }
            if (! $valid) {
                $this->invalid($path, 'Invalid value type.');
            }
        }
        if (isset($rule['enum']) && ! in_array($value, $rule['enum'], true)) {
            $this->invalid($path, 'Value is not allowed.');
        }
        if ($value === null) {
            return;
        }
        if (is_string($value)) {
            if (mb_strlen($value) < ($rule['minLength'] ?? 0) || mb_strlen($value) > ($rule['maxLength'] ?? PHP_INT_MAX)
                || (isset($rule['pattern']) && ! preg_match('~'.$rule['pattern'].'~u', $value))
                || (($rule['format'] ?? null) === 'uri' && ! filter_var($value, FILTER_VALIDATE_URL))
                || (($rule['format'] ?? null) === 'date' && (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || ! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))))) {
                $this->invalid($path, 'Value does not match the content schema.');
            }
        }
        if (is_int($value) && $value < ($rule['minimum'] ?? PHP_INT_MIN)) {
            $this->invalid($path, 'Value is below the minimum.');
        }
        if (! is_array($value)) {
            return;
        }
        foreach ($rule['required'] ?? [] as $key) {
            if (! array_key_exists($key, $value)) {
                $this->invalid($path.'.'.$key, 'Required field is missing.');
            }
        }
        if (($rule['additionalProperties'] ?? true) === false && array_diff(array_keys($value), array_keys($rule['properties'] ?? []))) {
            $this->invalid($path, 'Unexpected content field.');
        }
        foreach ($rule['properties'] ?? [] as $key => $property) {
            if (array_key_exists($key, $value)) {
                $this->check($value[$key], $property, $schema, $path.'.'.$key);
            }
        }
        if (isset($rule['items'])) {
            if (count($value) < ($rule['minItems'] ?? 0) || count($value) > ($rule['maxItems'] ?? PHP_INT_MAX)
                || (($rule['uniqueItems'] ?? false) && count(array_unique($value, SORT_REGULAR)) !== count($value))) {
                $this->invalid($path, 'Invalid array size or duplicate value.');
            }
            foreach ($value as $i => $item) {
                $this->check($item, $rule['items'], $schema, $path.'.'.$i);
            }
        }
    }

    private function invalid(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $key.': '.$message]);
    }
}
