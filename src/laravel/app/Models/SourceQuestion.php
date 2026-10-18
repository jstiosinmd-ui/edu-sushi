<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SourceQuestion extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public $incrementing = false;

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    protected $hidden = ['stem', 'options', 'source_key'];

    protected function casts(): array
    {
        return ['options' => 'array'];
    }

    public function file()
    {
        return $this->belongsTo(SourceFile::class, 'sha1', 'sha1');
    }

    public function group()
    {
        return $this->belongsTo(SourceGroup::class, 'group_id');
    }
}
