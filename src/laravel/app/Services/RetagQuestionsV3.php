<?php

namespace App\Services;

use App\Models\Question;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RetagQuestionsV3
{
    public const RELEASE = 'taxonomy-v3-20261018';

    public function run(bool $apply = false): array
    {
        return DB::transaction(function () use ($apply) {
            // Serialize application with imports and curator edits; dry runs are read-only.
            if ($apply) {
                DB::statement('LOCK TABLE questions IN SHARE ROW EXCLUSIVE MODE');
            }
            $map = Taxonomy::mappings();
            $questions = Question::with('currentVersion')->whereIn('id', array_keys($map))->orderBy('id')->get();
            if ($questions->count() !== count($map)) {
                throw ValidationException::withMessages(['bank' => 'Expected all 6,000 mapped questions. Import the existing bundled bank before retagging.']);
            }
            $applied = DB::table('question_taxonomy_changes')->where('release_id', self::RELEASE)->pluck('applied_version_id', 'question_id');
            $contents = [];
            $authorities = [];
            $converter = app(QuestionContentV3::class);
            foreach ($questions as $question) {
                if ($applied->has($question->id)) {
                    continue;
                }
                $content = $question->currentVersion->content;
                $mapping = $map[$question->id];
                if (isset($content['subject_code']) || QuestionContentV3::clinicalHash($content) !== $mapping['clinical_hash'] || $content['topic'] !== $mapping['legacy_topic']) {
                    throw ValidationException::withMessages(['bank' => $question->id.' differs from the mapped source. Review its mapping before retagging; no changes applied.']);
                }
                $content = $converter->convert($content, $mapping, $question->active);
                $converter->validate($content, registered: false);
                $contents[$question->id] = $content;
                foreach ($content['references'] as $ref) {
                    $book = preg_match('/^(FA2024|NEL21|BPOBG|BRS|HYANA)/', $ref['authority_id']);
                    $single = count($content['references']) === 1;
                    $authority = [
                        'id' => $ref['authority_id'], 'title' => $single ? trim(explode(';', $ref['citation'])[0]) : $ref['authority_id'],
                        'kind' => $book ? 'book' : 'web', 'mode' => $book ? 'book' : 'current_practice',
                        'url' => $ref['url'], 'registered_on' => '2026-10-18', 'registration_status' => 'legacy_unverified',
                    ];
                    if (! isset($authorities[$ref['authority_id']]) || ($single && $authorities[$ref['authority_id']]['title'] === $ref['authority_id'])) {
                        $authorities[$ref['authority_id']] = $authority;
                    }
                }
            }
            $summary = ['mode' => $apply ? 'applied' : 'dry-run', 'release' => self::RELEASE,
                'questions' => $questions->count(), 'to_retag' => count($contents), 'already_applied' => $applied->count(),
                'unassigned_subtopics' => count(array_filter($map, fn ($r) => ! $r['subtopic_code'])),
                'subjects_before' => $questions->countBy('subject')->all(),
                'subjects_after' => array_count_values(array_column($map, 'subject_code')),
                'subject_reassignments' => count(array_filter($map, fn ($r) => $r['legacy_subject'] !== Taxonomy::data()['subjects'][$r['subject_code']]['name'])),
                'source_groups_imported' => DB::table('source_groups')->count()];
            if (! $apply || ! $contents) {
                return $summary;
            }
            DB::table('bank_releases')->insertOrIgnore(['id' => self::RELEASE, 'applied_at' => now()]);
            foreach (array_chunk(array_values($authorities), 200) as $rows) {
                DB::table('authorities')->insertOrIgnore($rows);
            }
            foreach (array_chunk(array_values($contents), 200) as $chunk) {
                app(Catalog::class)->import(['items' => $chunk], notifyCorrections: false);
            }
            $versions = Question::with('currentVersion')->whereIn('id', array_keys($contents))->get()->keyBy('id');
            $changes = $releases = [];
            foreach ($questions as $question) {
                if (! isset($contents[$question->id])) {
                    continue;
                }
                $current = $versions[$question->id];
                $changes[] = ['release_id' => self::RELEASE, 'question_id' => $question->id,
                    'previous_version_id' => $question->current_version_id, 'applied_version_id' => $current->current_version_id,
                    'mapping' => json_encode($map[$question->id], JSON_UNESCAPED_UNICODE), 'created_at' => now()];
                $releases[] = ['release_id' => self::RELEASE, 'question_id' => $question->id, 'version' => $current->currentVersion->version];
            }
            foreach (array_chunk($changes, 200) as $rows) {
                DB::table('question_taxonomy_changes')->insert($rows);
            }
            foreach (array_chunk($releases, 200) as $rows) {
                DB::table('release_items')->insert($rows);
            }

            return $summary;
        });
    }
}
