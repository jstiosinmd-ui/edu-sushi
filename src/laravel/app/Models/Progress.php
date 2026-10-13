<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Progress extends Model
{
    protected $table = 'progress';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['selected_at' => 'datetime', 'completed_at' => 'datetime', 'bookmarked' => 'boolean', 'legacy' => 'boolean', 'latest_correct' => 'boolean'];
    }
}
