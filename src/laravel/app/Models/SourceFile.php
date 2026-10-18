<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SourceFile extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    public $incrementing = false;

    protected $primaryKey = 'sha1';

    protected $keyType = 'string';

    protected $hidden = ['path', 'copy_paths'];

    protected function casts(): array
    {
        return ['copy_paths' => 'array'];
    }

    public function instances()
    {
        return $this->hasMany(SourceQuestion::class, 'sha1', 'sha1');
    }
}
