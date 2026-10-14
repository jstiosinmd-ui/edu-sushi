<?php

namespace App\Services;

use App\Models\Question;
use App\Models\QuestionVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class Catalog
{
    public const HASH = '3cae311b133fe775562ce8d377a7fb41ee8faa3f65d64000ddfa966f4d48585d';

    public const CORE_SUBJECTS = ['Internal Medicine', 'OB-GYN', 'Pediatrics', 'Surgery'];

    public const SUBJECTS = [...self::CORE_SUBJECTS, 'Preventive Medicine', 'Anatomy', 'Legal Medicine-Ethics', 'Pathology', 'Pharmacology', 'Biochemistry'];

    public static function stemHash(string $stem): string
    {
        return hash('sha256', preg_replace('/\s+/u', ' ', mb_strtolower(trim($stem))));
    }

    public function validate(array $bank): array
    {
        Validator::make($bank, ['items' => 'required|array|min:1|max:10000'])->validate();
        $seen = [];
        foreach ($bank['items'] as $q) {
            if (! is_array($q)) {
                throw ValidationException::withMessages(['bank' => 'Each question must be an object.']);
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
            if (count(array_unique(array_map(fn ($x) => mb_strtolower(trim($x)), $q['options']))) !== 4) {
                throw ValidationException::withMessages(['bank' => 'Question '.$q['id'].' has duplicate options.']);
            }
        }

        return $bank;
    }

    public function import(array $bank, ?int $actor = null): array
    {
        $this->validate($bank);

        return DB::transaction(function () use ($bank, $actor) {
            $result = ['created' => 0, 'updated' => 0, 'unchanged' => 0];
            $existing = Question::with('currentVersion')->get()->keyBy('id');
            $position = (int) Question::max('position');
            foreach ($bank['items'] as $i => $content) {
                $content['status'] = 'Released';
                $q = $existing->get($content['id']) ?? new Question(['id' => $content['id']]);
                $previousVersion = $q->current_version_id;
                $hash = hash('sha256', json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                if ($q->exists && $q->currentVersion?->content_hash === $hash) {
                    $result['unchanged']++;

                    continue;
                }
                $result[$q->exists ? 'updated' : 'created']++;
                $q->fill(['subject' => $content['subject'], 'topic' => $content['topic'], 'difficulty' => $content['tier'], 'stem_hash' => self::stemHash($content['stem']), 'position' => $q->exists ? $q->position : ++$position])->save();
                $v = QuestionVersion::create(['question_id' => $q->id, 'version' => (QuestionVersion::where('question_id', $q->id)->max('version') ?? 0) + 1, 'content' => $content, 'content_hash' => $hash, 'created_by' => $actor]);
                $q->update(['current_version_id' => $v->id]);
                if ($previousVersion) {
                    app(QuestionNotices::class)->correction($q->fresh());
                }
            }

            return $result;
        });
    }

    public function items(): array
    {
        return Question::where('active', true)->with('currentVersion')->orderBy('position')->orderBy('id')->get()->map(fn ($q) => [...$q->currentVersion->content, 'version' => $q->currentVersion->version, 'version_id' => $q->current_version_id])->all();
    }

    public static function publicQuestion(array $q, bool $feedback = false): array
    {
        $out = array_intersect_key($q, array_flip(['id', 'subject', 'topic', 'tier', 'stem', 'options', 'version']));
        if ($feedback) {
            $out += ['key' => $q['key'], 'rationale' => $q['rationale'], 'expl' => $q['expl'], 'source' => strip_tags($q['raw']['Answer Source'] ?? '')];
        }

        return $out;
    }
}
