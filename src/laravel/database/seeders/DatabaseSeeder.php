<?php

namespace Database\Seeders;

use App\Models\Question;
use App\Models\User;
use App\Services\Catalog;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['powquanico' => 'student', 'hannahquintero' => 'student', 'rachirpunla' => 'student', 'jstiosin' => 'admin'] as $username => $role) {
            User::firstOrCreate(['username' => $username], ['name' => $username, 'role' => $role, 'password' => 'password', 'must_change_password' => true]);
        }
        $bank = json_decode(file_get_contents(database_path('data/bank.json')), true, 512, JSON_THROW_ON_ERROR);
        $existing = Question::pluck('id')->flip();
        $bank['items'] = array_values(array_filter($bank['items'], fn ($q) => ! $existing->has($q['id'])));
        if ($bank['items']) {
            app(Catalog::class)->import($bank);
        }
    }
}
