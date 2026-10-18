<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subject extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public $incrementing = false;

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public function topics()
    {
        return $this->hasMany(Topic::class, 'subject_code', 'code');
    }
}
