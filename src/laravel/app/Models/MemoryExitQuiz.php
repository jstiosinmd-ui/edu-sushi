<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MemoryExitQuiz extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['items' => 'array', 'answers' => 'array', 'finished_at' => 'datetime'];
    }
}
