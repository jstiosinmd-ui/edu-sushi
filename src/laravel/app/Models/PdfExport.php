<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PdfExport extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['explanations' => 'boolean'];
    }

    public function exam()
    {
        return $this->belongsTo(Exam::class);
    }
}
