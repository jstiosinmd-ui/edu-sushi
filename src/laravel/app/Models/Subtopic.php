<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subtopic extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public $incrementing = false;

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public function topic()
    {
        return $this->belongsTo(Topic::class, 'topic_code', 'code');
    }

    public function questions()
    {
        return $this->hasMany(Question::class, 'subtopic_code', 'code');
    }
}
