<?php

namespace App\Services;

use App\Models\Attempt;
use App\Models\Exam;
use App\Models\FlashcardDeck;
use App\Models\Question;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class Passport
{
    public static function definitions(): array
    {
        $rows = [
            ['fresh_first', 'Fresh Catch', 'First Catch', 'Complete a full 10-question catch.', 1],
            ['fresh_perfect', 'Fresh Catch', 'Perfect Platter', 'Get all 10 first submitted answers correct in one catch.', 1],
            ['fresh_second', 'Fresh Catch', 'Second Serving', 'Correct 10 distinct previously missed questions in Fresh Catch.', 10],
            ['fresh_week', 'Fresh Catch', 'Catch of the Week', 'Complete catches on five days in one Monday-to-Sunday week.', 5],
            ['fresh_50', 'Fresh Catch', 'Seasoned Fisher', 'Complete 50 full catches.', 50],
            ['tutor_first', 'Maki Mentor', 'First Roll', 'Complete a tutor session with at least 10 answered questions.', 1],
            ['tutor_100', 'Maki Mentor', 'Mentor’s Apprentice', 'Answer and open tutor feedback for 100 distinct questions.', 100],
            ['tutor_comeback', 'Maki Mentor', 'Comeback Chef', 'Correct 25 distinct previously missed questions in tutor mode.', 25],
            ['tutor_four', 'Maki Mentor', 'Mentor’s Table', 'Answer and review 25 distinct questions in each subject.', 100],
            ['rush_first', 'Wasabi Rush', 'First Heat', 'Answer every question in a timed exam of at least 20 questions.', 1],
            ['rush_cool', 'Wasabi Rush', 'Cool Under Wasabi', 'Score at least 80% on a fully answered timed exam of at least 20 questions.', 1],
            ['rush_triple', 'Wasabi Rush', 'Triple Heat', 'Earn three qualifying 80% results covering at least 40 distinct questions overall.', 3],
            ['rush_four', 'Wasabi Rush', 'Full-Service Rush', 'Complete a timed exam with at least 10 answered questions per subject.', 1],
            ['memory_first', 'Memory Maki', 'First Wrap', 'Answer and rate 20 distinct cards.', 20],
            ['memory_ready', 'Memory Maki', 'Ready to Roll', 'Have at least 80% rated 5 in a deck of at least 20 cards.', 80],
            ['memory_golden', 'Memory Maki', 'Golden Maki', 'Have all cards rated 5 in a deck of at least 20 cards.', 100],
            ['memory_rescue', 'Memory Maki', 'Sticky Rice Rescue', 'Move 20 distinct questions from a recorded rating of 1 or 2 to 5.', 20],
            ['memory_four', 'Memory Maki', 'Four-Flavor Memory', 'Have 25 questions currently rated 5 in each subject.', 100],
            ['bento_first', 'Bento Builder', 'Lunchbox Launch', 'Save an exam with at least 20 questions.', 1],
            ['bento_four', 'Bento Builder', 'Four-Flavor Bento', 'Save an exam with at least 10 questions per subject.', 1],
            ['bento_serve', 'Bento Builder', 'Ready to Serve', 'Generate a paper, answer sheet, and key with explanations for one saved exam.', 1],
            ['bento_chef', 'Bento Builder', 'Bento Chef', 'Save and export an exam with at least 10 questions per subject.', 1],
            ['bento_prep', 'Bento Builder', 'Meal Prepper', 'Save and export five exams with different question sets.', 5],
            ['library_notes', 'Roll Call', 'Nori Notes', 'Bookmark 10 distinct questions.', 10],
            ['library_sampler', 'Roll Call', 'Crew Sampler', 'Open five distinct explanations from each subject.', 20],
            ['library_comeback', 'Roll Call', 'Bookmark Comeback', 'Correctly answer 10 distinct questions while bookmarked.', 10],
            ['library_100', 'Roll Call', 'Reference Regular', 'Open explanations for 100 distinct library questions.', 100],
            ['streak_3', 'Daily habits', 'Three-Day Tray', 'Complete three study days in a continuous catch streak.', 3],
            ['streak_7', 'Daily habits', 'Sushi Train', 'Complete seven study days in a continuous catch streak.', 7],
            ['streak_14', 'Daily habits', 'Fortnight Feast', 'Complete 14 study days in a continuous catch streak.', 14],
            ['streak_30', 'Daily habits', 'Thirty-Day Omakase', 'Complete 30 study days in a continuous catch streak.', 30],
            ['days_100', 'Daily habits', 'Counter Regular', 'Complete catches on 100 days overall.', 100],
            ['comeback', 'Daily habits', 'Welcome Back Roll', 'Complete a catch after at least three inactive days.', 1],
        ];
        foreach (['im' => 'Internal Medicine', 'pedia' => 'Pediatrics', 'surgery' => 'Surgery', 'ob' => 'OB-GYN'] as $id => $subject) {
            foreach ([50 => 'Meet the Chef', 100 => 'Sakura Specialist', 200 => 'Golden Specialist'] as $n => $name) {
                $rows[] = ['subject_'.$id.'_'.$n, $subject, $name, 'Correctly answer '.$n.' distinct '.$subject.' questions.', $n];
            }
        }

        return collect($rows)->mapWithKeys(fn ($r) => [$r[0] => ['id' => $r[0], 'group' => $r[1], 'name' => $r[2], 'requirement' => $r[3], 'target' => $r[4]]])->all();
    }

    public function for(User $u): array
    {
        $values = array_fill_keys(array_keys(self::definitions()), 0);
        $events = DB::table('study_events')->where('user_id', $u->id)->where('mode', '!=', 'assigned')->orderBy('occurred_at')->orderBy('id')->get();
        $missed = [];
        $recoveries = ['fresh' => [], 'tutor' => []];
        $bookmarked = [];
        foreach ($events as $e) {
            if ($e->correct) {
                if (isset($missed[$e->question_hash]) && isset($recoveries[$e->mode])) {
                    $recoveries[$e->mode][$e->question_hash] = true;
                }
                if ($e->bookmarked) {
                    $bookmarked[$e->question_hash] = true;
                }
            } else {
                $missed[$e->question_hash] = true;
            }
        }
        $values['fresh_second'] = count($recoveries['fresh']);
        $values['tutor_comeback'] = count($recoveries['tutor']);
        $values['library_comeback'] = count($bookmarked);
        $views = DB::table('learning_views')->where('user_id', $u->id)->get();
        $tutor = $views->where('mode', 'tutor');
        $values['tutor_100'] = $tutor->count();
        $values['tutor_four'] = $this->subjectProgress($tutor, 25);
        $library = $views->where('mode', 'library');
        $values['library_100'] = $library->count();
        $values['library_sampler'] = $this->subjectProgress($library, 5);
        $values['library_notes'] = DB::table('progress')->join('questions', 'questions.id', '=', 'progress.question_id')->where('user_id', $u->id)->where('bookmarked', true)->distinct()->count('stem_hash');
        $tripleIds = [];
        $tripleCount = 0;
        foreach (Attempt::where('user_id', $u->id)->where('status', '!=', 'active')->with('items')->get() as $a) {
            $count = $a->items->count();
            $answered = $a->items->whereNotNull('answer')->count();
            if ($a->mode === 'fresh' && $a->status === 'finished' && $count === 10 && $answered === 10) {
                $values['fresh_first'] = 1;
                $values['fresh_50']++;
                if ($a->score === 10) {
                    $values['fresh_perfect'] = 1;
                }
            }
            if ($a->mode === 'tutor' && $answered >= 10) {
                $values['tutor_first'] = 1;
            }
            if ($a->mode === 'exam' && $count >= 20 && $answered === $count) {
                $values['rush_first'] = 1;
                if ($a->score / $count >= .8) {
                    $values['rush_cool'] = 1;
                    $tripleCount++;
                    foreach ($a->items as $item) {
                        $tripleIds[Catalog::stemHash($item->snapshot['stem'])] = true;
                    }
                }
                $subjects = $a->items->map(fn ($i) => (object) ['subject' => $i->snapshot['subject']]);
                if ($this->subjectProgress($subjects, 10) === 40) {
                    $values['rush_four'] = 1;
                }
            }
        }
        $values['rush_triple'] = count($tripleIds) >= 40 ? $tripleCount : min(2, $tripleCount);
        foreach (FlashcardDeck::where('user_id', $u->id)->with('items')->get() as $deck) {
            if ($deck->items->count() < 20) {
                continue;
            }
            $pct = (int) floor($deck->items->where('confidence', 5)->count() / $deck->items->count() * 100);
            $values['memory_ready'] = max($values['memory_ready'], $pct);
            $values['memory_golden'] = max($values['memory_golden'], $pct);
        }
        $reviews = DB::table('flashcard_reviews')->join('questions', 'questions.id', '=', 'flashcard_reviews.question_id')->where('user_id', $u->id)->orderBy('flashcard_reviews.created_at')->orderBy('flashcard_reviews.id')->get(['questions.stem_hash', 'flashcard_reviews.confidence']);
        $values['memory_first'] = $reviews->unique('stem_hash')->count();
        $low = [];
        $rescued = [];
        foreach ($reviews as $review) {
            if ($review->confidence <= 2) {
                $low[$review->stem_hash] = true;
            }
            if ($review->confidence === 5 && isset($low[$review->stem_hash])) {
                $rescued[$review->stem_hash] = true;
            }
        }
        $values['memory_rescue'] = count($rescued);
        $confident = DB::table('flashcards')->join('questions', 'questions.id', '=', 'flashcards.question_id')->where('user_id', $u->id)->orderByDesc('flashcards.updated_at')->get(['subject', 'stem_hash', 'confidence'])->unique('stem_hash')->where('confidence', 5);
        $values['memory_four'] = $this->subjectProgress($confident, 25);
        $sets = [];
        foreach (Exam::where('user_id', $u->id)->with('exports')->get() as $exam) {
            if (count($exam->items) >= 20) {
                $values['bento_first'] = 1;
            }
            $four = $this->subjectProgress(collect($exam->items)->map(fn ($q) => (object) $q), 10) === 40;
            if ($four) {
                $values['bento_four'] = 1;
            }
            if ($exam->exports->where('status', 'ready')->isNotEmpty()) {
                $ids = array_map(fn ($q) => Catalog::stemHash($q['stem']), $exam->items);
                sort($ids);
                $sets[hash('sha256', implode(',', $ids))] = true;
                if ($four) {
                    $values['bento_chef'] = 1;
                }
                if ($exam->exports->where('status', 'ready')->where('explanations', true)->isNotEmpty()) {
                    $values['bento_serve'] = 1;
                }
            }
        }
        $values['bento_prep'] = count($sets);
        $habits = app(Habits::class)->for($u);
        foreach ([3, 7, 14, 30] as $n) {
            $values['streak_'.$n] = $habits['longest'];
        }
        $values['days_100'] = $habits['total_days'];
        $values['fresh_week'] = $habits['best_week'];
        $values['comeback'] = $habits['comeback'];
        foreach (['im' => 'Internal Medicine', 'pedia' => 'Pediatrics', 'surgery' => 'Surgery', 'ob' => 'OB-GYN'] as $id => $subject) {
            $n = $events->where('subject', $subject)->where('correct', true)->unique('question_hash')->count();
            foreach ([50, 100, 200] as $target) {
                $values['subject_'.$id.'_'.$target] = $n;
            }
        }
        foreach (self::definitions() as $id => $badge) {
            if ($values[$id] >= $badge['target']) {
                DB::table('earned_badges')->insertOrIgnore(['user_id' => $u->id, 'badge' => $id, 'earned_at' => now()]);
            }
        }
        $earned = DB::table('earned_badges')->where('user_id', $u->id)->pluck('earned_at', 'badge');
        $closet = Closet::all($u);

        return array_values(array_map(function ($b) use ($values, $earned, $closet) {
            $b['progress'] = min($b['target'], $values[$b['id']]);
            $b['earned_at'] = $earned[$b['id']] ?? null;
            $b['rewards'] = array_values(array_map(fn ($a) => ['name' => $a['name'], 'image' => $a['image'], 'frame' => $a['frame']], array_filter($closet, fn ($a) => $a['badge'] === $b['id'])));
            if (in_array($b['group'], Catalog::SUBJECTS, true)) {
                $b['available'] = Question::where('subject', $b['group'])->where('active', true)->distinct()->count('stem_hash');
            }

            return $b;
        }, self::definitions()));
    }

    private function subjectProgress($items, int $each): int
    {
        return array_sum(array_map(fn ($s) => min($each, $items->where('subject', $s)->count()), Catalog::SUBJECTS));
    }
}
