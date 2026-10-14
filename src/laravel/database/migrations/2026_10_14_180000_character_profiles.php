<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Private historical files are retained; photo serving is removed.
        DB::table('users')->where('avatar', 'photo')->update(['avatar' => 'rice']);
        $map = ['catch-sakura' => 'toro', 'catch-gold' => 'unagi', 'mentor-sakura' => 'inari', 'mentor-gold' => 'futomaki',
            'wasabi-sakura' => 'dragon', 'wasabi-gold' => 'oshi', 'memory-sakura' => 'ikura', 'memory-gold' => 'gunkan',
            'bento-sakura' => 'butler', 'bento-gold' => 'battera', 'nori-sakura' => 'temari', 'nori-gold' => 'tekka',
            'im-sakura' => 'aburi', 'im-gold' => 'saba', 'pedia-sakura' => 'tamago', 'pedia-gold' => 'temaki',
            'surgery-sakura' => 'scalpel', 'surgery-gold' => 'surgeon', 'ob-sakura' => 'sakura', 'ob-gold' => 'pearl', 'rice-wasabi' => 'conductor'];
        foreach ($map as $old => $new) {
            DB::table('users')->where('avatar', $old)->update(['avatar' => $new]);
        }
    }

    public function down(): void {}
};
