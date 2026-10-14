<?php

namespace Tests\Unit;

use App\Services\Achievements;
use App\Services\Draw;
use App\Services\WeightedDeck;
use PHPUnit\Framework\TestCase;

class EngineTest extends TestCase
{
    public function test_fixed_two_hundred_question_tiers_extend_indefinitely(): void
    {
        $this->assertSame('Bronze', Achievements::at(199)['name']);
        $this->assertSame('Silver', Achievements::at(200)['name']);
        $this->assertSame(400, Achievements::at(200)['threshold']);
        $this->assertSame('Sakura 10', Achievements::at(2400)['name']);
        $this->assertSame(50200, Achievements::at(50000)['threshold']);
    }

    public function test_weighted_deck_draws_low_confidence_six_times_as_often(): void
    {
        $items = [['question_id' => 'low', 'confidence' => 1], ['question_id' => 'high', 'confidence' => 5]];
        $counts = ['low' => 0, 'high' => 0];
        for ($ticket = 1; $ticket <= 7; $ticket++) {
            $counts[(new WeightedDeck)->choose($items, $ticket)]++;
        }
        $this->assertSame(['low' => 6, 'high' => 1], $counts);
        $all = [];
        for ($i = 0; $i <= 5; $i++) {
            $all[] = ['question_id' => (string) $i, 'confidence' => $i ?: null];
        }
        $counts = array_fill(0, 6, 0);
        for ($t = 1; $t <= 22; $t++) {
            $counts[(int) (new WeightedDeck)->choose($all, $t)]++;
        }
        $this->assertSame([6, 6, 4, 3, 2, 1], $counts);
    }

    public function test_php_draw_matches_retained_javascript_golden_cases(): void
    {
        $bank = json_decode(file_get_contents(__DIR__.'/../../database/data/bank.json'), true)['items'];
        $cases = json_decode(file_get_contents(__DIR__.'/../Fixtures/draw-cases.json'), true);
        foreach ($cases as $c) {
            $this->assertSame($c['ids'], array_column((new Draw)->select($bank, $c['settings']), 'id'));
        }
    }
}
