<?php

namespace App\Services;

use App\Models\Question;
use App\Models\QuestionVersion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class Catalog
{
    public const HASH = '3cae311b133fe775562ce8d377a7fb41ee8faa3f65d64000ddfa966f4d48585d';

    public const CORE_SUBJECTS = ['Internal Medicine', 'OB-GYN', 'Pediatrics', 'Surgery'];

    public const SUBJECTS = [...self::CORE_SUBJECTS, 'Preventive Medicine', 'Anatomy', 'Legal Medicine-Ethics', 'Pathology', 'Pharmacology', 'Biochemistry', 'Physiology', 'Microbiology-Parasitology'];

    public static function stemHash(string $stem): string
    {
        return hash('sha256', preg_replace('/\s+/u', ' ', mb_strtolower(trim($stem))));
    }

    public function validate(array $bank): array
    {
        Validator::make($bank, ['items' => 'required|array|min:1|max:10000'])->validate();
        $requiresV3 = DB::table('bank_releases')->where('id', RetagQuestionsV3::RELEASE)->exists();
        $seen = [];
        foreach ($bank['items'] as $q) {
            if (! is_array($q)) {
                throw ValidationException::withMessages(['bank' => 'Each question must be an object.']);
            }
            if ($requiresV3 && ! isset($q['subject_code'])) {
                throw ValidationException::withMessages(['bank' => 'This bank uses controlled taxonomy. Import version 3 content with registered codes and references.']);
            }
            // Validate one record at a time: wildcard expansion over a whole
            // clinical bank otherwise grows quadratically with its size.
            Validator::make($q, ['id' => 'required|string|max:80', 'subject' => 'required|in:'.implode(',', self::SUBJECTS),
                'topic' => 'required|string|max:255', 'tier' => 'required|in:Easy,Average,Difficult', 'stem' => 'required|string|max:20000',
                'options' => 'required|array|list|size:4', 'options.*' => 'required|string|max:10000', 'key' => 'required|in:A,B,C,D',
                'rationale' => 'required|string|max:40000', 'expl' => 'required|array|list|size:4', 'expl.*' => 'nullable|string|max:20000', 'raw' => 'required|array'])->validate();
            if (isset($seen[$q['id']])) {
                throw ValidationException::withMessages(['bank' => 'Duplicate question ID: '.$q['id']]);
            }
            $seen[$q['id']] = true;
            if (isset($q['subject_code']) || isset($q['schema_version'])) {
                app(QuestionContentV3::class)->validate($q);
            }
            if (count(array_unique(array_map(fn ($x) => mb_strtolower(trim($x)), $q['options']))) !== 4) {
                throw ValidationException::withMessages(['bank' => 'Question '.$q['id'].' has duplicate options.']);
            }
        }

        return $bank;
    }

    public static function activeContent(array $content): array
    {
        $content['source_review_status'] ??= $content['raw']['Review Status'] ?? null;
        $content['status'] = 'Active';
        $content['raw']['Review Status'] = 'Active';
        if (isset($content['review'])) {
            $content['review']['status'] = 'Active';
        }

        return $content;
    }

    public function import(array $bank, ?int $actor = null, bool $notifyCorrections = true): array
    {
        return DB::transaction(function () use ($bank, $actor, $notifyCorrections) {
            // Wait for any retag transaction before checking the release and writing.
            DB::statement('LOCK TABLE questions IN ROW EXCLUSIVE MODE');
            $this->validate($bank);
            $result = ['created' => 0, 'updated' => 0, 'unchanged' => 0];
            $existing = Question::with('currentVersion')->whereIn('id', array_column($bank['items'], 'id'))->lockForUpdate()->get()->keyBy('id');
            $position = (int) Question::max('position');
            foreach ($bank['items'] as $i => $content) {
                $content = isset($content['subject_code']) ? $content : self::activeContent($content);
                $q = $existing->get($content['id']) ?? new Question(['id' => $content['id']]);
                if ($q->subject_code && ! isset($content['subject_code'])) {
                    throw ValidationException::withMessages(['bank' => 'A version 3 question cannot be replaced by a legacy import. Export and edit its current content.']);
                }
                $previousVersion = $q->current_version_id;
                $hash = hash('sha256', json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                if ($q->exists && $q->currentVersion?->content_hash === $hash) {
                    $result['unchanged']++;

                    continue;
                }
                $result[$q->exists ? 'updated' : 'created']++;
                $fields = ['subject' => $content['subject'], 'topic' => $content['topic'], 'difficulty' => $content['tier'], 'stem_hash' => self::stemHash($content['stem']), 'position' => $q->exists ? $q->position : ++$position];
                if (isset($content['subject_code'])) {
                    $fields += ['subject_code' => $content['subject_code'], 'topic_code' => $content['topic_code'],
                        'subtopic_code' => $content['subtopic_code'] ?? null, 'concept_key' => $content['concept_key'] ?? null,
                        'origin' => $content['lineage']['origin'], 'status' => $content['review']['status'],
                        'active' => $content['review']['status'] === 'Active', 'cognitive' => $content['cognitive'] ?? null, 'qtype' => $content['qtype'] ?? null];
                }
                $q->fill($fields)->save();
                $v = QuestionVersion::create(['question_id' => $q->id, 'version' => (QuestionVersion::where('question_id', $q->id)->max('version') ?? 0) + 1, 'content' => $content, 'content_hash' => $hash, 'created_by' => $actor]);
                $q->update(['current_version_id' => $v->id]);
                foreach ($content['references'] ?? [] as $ref) {
                    DB::table('question_references')->insert(['question_id' => $q->id, 'version' => $v->version,
                        'authority_id' => $ref['authority_id'], 'citation' => $ref['citation'], 'locator' => $ref['locator'],
                        'url' => $ref['url'] ?? null, 'accessed_on' => $ref['accessed'] ?? null,
                        'supports' => $ref['supports'] ?? 'key', 'verification_status' => $ref['verification_status'] ?? 'legacy_unverified']);
                }
                foreach ($content['lineage']['source_groups'] ?? [] as $group) {
                    DB::table('question_sources')->updateOrInsert(['question_id' => $q->id, 'group_id' => $group],
                        ['role' => $content['lineage']['origin'] === 'authority_authored' ? 'topic_map' : 'revised_from']);
                }
                if ($previousVersion && $notifyCorrections) {
                    app(QuestionNotices::class)->correction($q->fresh());
                }
            }

            return $result;
        });
    }

    /** Selection metadata deliberately excludes clinical content and answer keys. */
    public function candidates(array $filters = []): array
    {
        $query = Question::where('active', true)->whereNotNull('current_version_id');
        if (isset($filters['subjects'])) {
            $query->whereIn('subject', $filters['subjects']);
        }
        if (isset($filters['ids'])) {
            $query->whereIn('id', $filters['ids']);
        }
        if (($filters['difficulty'] ?? 'All') !== 'All') {
            $query->where('difficulty', $filters['difficulty']);
        }
        $rows = $query->orderBy('position')->orderBy('id')->get(['id', 'subject', 'topic', 'subject_code', 'topic_code', 'subtopic_code', 'difficulty', 'stem_hash', 'current_version_id'])
            ->map(fn ($q) => [...$q->only('id', 'subject', 'topic', 'subject_code', 'topic_code', 'subtopic_code', 'stem_hash'), 'tier' => $q->difficulty, 'version_id' => $q->current_version_id])->all();

        return array_values(array_filter($rows, fn ($q) => Taxonomy::matchesAny($q, $filters['topics'] ?? [])));
    }

    public static function questionHash(array $question): string
    {
        return $question['stem_hash'] ?? self::stemHash($question['stem']);
    }

    /** Preserve selection order and pin the immutable version seen during selection. */
    public function hydrate(array $selected): array
    {
        $versions = QuestionVersion::whereIn('id', array_column($selected, 'version_id'))->get()->keyBy('id');

        return array_map(function ($q) use ($versions) {
            // Existing snapshots may also pass through this boundary.
            if (isset($q['stem'])) {
                return $q;
            }
            $version = $versions->get($q['version_id']);
            if (! $version || $version->question_id !== $q['id']) {
                throw ValidationException::withMessages(['count' => 'A selected question is unavailable. Start again.']);
            }

            return [...$version->content, 'version' => $version->version, 'version_id' => $version->id,
                ...(isset($q['fresh_kind']) ? ['fresh_kind' => $q['fresh_kind']] : [])];
        }, $selected);
    }

    public function summary(): array
    {
        $revision = (int) DB::table('catalog_revisions')->where('id', 1)->value('revision');
        $load = function () use ($revision) {
            $topics = Question::where('active', true)->whereNotNull('current_version_id')->select('subject', 'topic', 'subject_code', 'topic_code')
                ->selectRaw('COUNT(*) as count')->groupBy('subject', 'topic', 'subject_code', 'topic_code')->orderBy('subject')->orderBy('topic')->get()->toArray();

            return ['subjects' => self::SUBJECTS, 'topics' => $topics, 'total' => array_sum(array_column($topics, 'count')), 'revision' => $revision];
        };

        // Uncommitted imports must never populate a shared cache entry.
        return DB::transactionLevel() > 0 ? $load() : Cache::remember('catalog:summary:v1:'.$revision, 3600, $load);
    }

    public function items(?array $ids = null): array
    {
        return $this->hydrate($this->candidates($ids === null ? [] : ['ids' => $ids]));
    }

    public static function publicQuestion(array $q, bool $feedback = false): array
    {
        $out = array_intersect_key($q, array_flip(['id', 'subject', 'subject_code', 'topic', 'topic_code', 'subtopic_code', 'tier', 'stem', 'options', 'version']));
        if ($feedback) {
            $citations = isset($q['references']) ? implode('; ', array_unique(array_column($q['references'], 'citation'))) : ($q['raw']['Answer Source'] ?? '');
            $out += ['key' => $q['key'], 'rationale' => $q['rationale'], 'expl' => $q['expl'], 'source' => strip_tags($citations)];
        }

        return $out;
    }
}
