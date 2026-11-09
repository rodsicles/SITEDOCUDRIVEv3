<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Adds the Admin role only. No account or password is seeded; the Dean creates Admin accounts in the app.
return new class extends Migration {
    public function up(): void
    {
        if (!DB::table('roles')->where('role_name', 'Admin')->exists()) {
            DB::table('roles')->insert(['role_name' => 'Admin', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        $roleId = DB::table('roles')->where('role_name', 'Admin')->value('role_id');
        if ($roleId && !DB::table('users')->where('role_id', $roleId)->exists()) {
            DB::table('roles')->where('role_id', $roleId)->delete();
        }
    }
};
