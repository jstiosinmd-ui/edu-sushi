<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $guarded = ['id'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['password' => 'hashed', 'disabled' => 'boolean', 'must_change_password' => 'boolean', 'last_active_at' => 'datetime',
            'pinned_badges' => 'array', 'fresh_subjects' => 'array', 'reminder_enabled' => 'boolean', 'reminder_snoozed_until' => 'datetime', 'timezone_changed_at' => 'datetime'];
    }
}
