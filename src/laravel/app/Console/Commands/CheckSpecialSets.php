<?php

namespace App\Console\Commands;

use App\Services\SpecialSets;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CheckSpecialSets extends Command
{
    protected $signature = 'sushi:check-special-sets';

    protected $description = 'Check special-set content and progress schema without changing learner data';

    public function handle(SpecialSets $sets): int
    {
        if (! Schema::hasColumns('special_set_progress', ['user_id', 'slug', 'content_hash', 'answers'])) {
            $this->error('Special-set progress migration is missing.');

            return self::FAILURE;
        }
        DB::table('special_set_progress')->whereRaw('1 = 0')->get();
        $total = $review = 0;
        foreach (SpecialSets::SLUGS as $slug) {
            $set = $sets->load($slug);
            if (array_column($set['items'], 'number') !== range(1, 100)) {
                $this->error('Invalid question sequence: '.$slug);

                return self::FAILURE;
            }
            foreach ($set['items'] as $q) {
                if (! $q['stem'] || ! $q['rationale'] || count($q['options']) < 3 || count($q['options']) > 5
                    || ($q['review_only'] ? $q['key'] !== null : ! array_key_exists($q['key'], $q['options']))
                    || (isset($q['image']) && ! is_file(database_path('data/special-sets/'.$q['image'])))) {
                    $this->error('Invalid question: '.$q['id']);

                    return self::FAILURE;
                }
                $total++;
                $review += (int) $q['review_only'];
            }
            $this->info($set['title'].': 100 questions; '.(100 - $sets->summary($set, [])['scorable']).' review-only.');
        }
        $this->info("Special sets ready: {$total} questions, {$review} review-only; progress schema available.");

        return self::SUCCESS;
    }
}
