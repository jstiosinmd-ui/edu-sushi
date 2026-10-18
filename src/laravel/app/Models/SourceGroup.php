<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SourceGroup extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public $incrementing = false;

    protected $primaryKey = 'id';

    protected $keyType = 'int';

    protected $hidden = ['questions', 'source_questions'];

    public function instances()
    {
        return $this->hasMany(SourceQuestion::class, 'group_id');
    }
}
