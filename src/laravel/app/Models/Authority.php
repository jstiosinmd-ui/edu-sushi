<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Authority extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public $incrementing = false;

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public function references()
    {
        return $this->hasMany(QuestionReference::class, 'authority_id');
    }
}
