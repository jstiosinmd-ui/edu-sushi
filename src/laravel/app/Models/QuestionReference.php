<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuestionReference extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public function authority()
    {
        return $this->belongsTo(Authority::class, 'authority_id');
    }

    public function question()
    {
        return $this->belongsTo(Question::class);
    }
}
