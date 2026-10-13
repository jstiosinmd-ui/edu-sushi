<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Attempt extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['settings' => 'array', 'deadline' => 'datetime', 'finished_at' => 'datetime', 'heartbeat_at' => 'datetime'];
    }

    public function items()
    {
        return $this->hasMany(AttemptItem::class)->orderBy('position');
    }

    public function assignment()
    {
        return $this->belongsTo(Assignment::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
