<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('leaderboard_enabled')->default(false);
            $t->timestamp('archived_at')->nullable();
        });
        DB::transaction(function () {
            $retired = DB::table('users')->where('username', 'jstiosin-student')->lockForUpdate()->first();
            if ($retired) {
                DB::table('users')->where('id', $retired->id)->update(['disabled' => true, 'archived_at' => now(), 'auth_version' => $retired->auth_version + 1, 'updated_at' => now()]);
                DB::table('sessions')->where('user_id', $retired->id)->delete();
                DB::table('learner_invitations')->where('user_id', $retired->id)->whereNull('accepted_at')->update(['revoked_at' => now()]);
            }
            DB::table('users')->where('username', 'jstiosin')->where('role', 'admin')->update(['leaderboard_enabled' => true]);
        });
    }

    public function down(): void {}
};
