<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuestionReport extends Model
{
    protected $guarded = [];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function version()
    {
        return $this->belongsTo(QuestionVersion::class, 'question_version_id');
    }
}
