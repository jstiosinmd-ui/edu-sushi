<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Topic extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public $incrementing = false;

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public function subject()
    {
        return $this->belongsTo(Subject::class, 'subject_code', 'code');
    }

    public function subtopics()
    {
        return $this->hasMany(Subtopic::class, 'topic_code', 'code');
    }
}
