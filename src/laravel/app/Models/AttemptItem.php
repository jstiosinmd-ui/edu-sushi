<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttemptItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'correct' => 'boolean', 'graded_at' => 'datetime'];
    }
}
