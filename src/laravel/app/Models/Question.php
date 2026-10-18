<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Question extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::saving(function (self $question) {
            if ($question->isDirty('status')) {
                $question->active = $question->status === 'Active';
            } elseif ($question->isDirty('active')) {
                $question->status = $question->active ? 'Active' : 'Retired';
            }
        });
    }

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function currentVersion()
    {
        return $this->belongsTo(QuestionVersion::class, 'current_version_id');
    }

    public function taxonomyTopic()
    {
        return $this->belongsTo(Topic::class, 'topic_code', 'code');
    }

    public function subtopic()
    {
        return $this->belongsTo(Subtopic::class, 'subtopic_code', 'code');
    }

    public function references()
    {
        return $this->hasMany(QuestionReference::class);
    }

    public function sources()
    {
        return $this->belongsToMany(SourceGroup::class, 'question_sources', 'question_id', 'group_id')->withPivot('role');
    }
}
