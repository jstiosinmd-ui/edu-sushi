<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FlashcardDeck extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    public function items()
    {
        return $this->hasMany(FlashcardDeckItem::class)->orderBy('id');
    }
}
