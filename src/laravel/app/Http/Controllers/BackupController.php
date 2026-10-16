<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class BackupController extends Controller
{
    public function index()
    {
        return Inertia::render('Backups', ['enabled' => (bool) config('backups.enabled'), 'time' => config('backups.time'),
            'mirror' => (bool) config('backups.mirror_disk'), 'weekly' => (bool) config('backups.weekly_rehearsal'),
            'runs' => DB::table('backup_runs')->orderByDesc('created_at')->limit(30)->get(['id', 'status', 'bytes', 'mirrored', 'created_at', 'finished_at', 'verified_at', 'error'])]);
    }
}
