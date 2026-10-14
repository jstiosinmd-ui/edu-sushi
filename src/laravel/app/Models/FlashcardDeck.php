<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FlashcardDeck extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['settings' => 'array', 'session_finished_at' => 'datetime', 'goal_prompt_dismissed' => 'boolean'];
    }

    public function items()
    {
        return $this->hasMany(FlashcardDeckItem::class)->orderBy('id');
    }
}
