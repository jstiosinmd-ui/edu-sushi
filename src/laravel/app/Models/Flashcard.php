<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Flashcard extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['confidence' => 'integer', 'reviews' => 'integer'];
    }
}
