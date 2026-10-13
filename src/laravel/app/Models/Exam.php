<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Exam extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['settings' => 'array', 'items' => 'array'];
    }

    public function exports()
    {
        return $this->hasMany(PdfExport::class)->latest();
    }
}
