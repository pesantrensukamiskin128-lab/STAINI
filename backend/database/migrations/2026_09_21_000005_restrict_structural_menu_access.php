<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // WK2 dapat melihat SDM tanpa memperoleh hak mutasi.
        foreach (['WK2', 'WR2'] as $position) {
            DB::table('position_permissions')->updateOrInsert(
                ['position_code' => $position, 'permission_name' => 'mahasiswa.view'],
                ['created_at' => now(), 'updated_at' => now()]
            );
        }
    }

    public function down(): void
    {
        DB::table('position_permissions')
            ->whereIn('position_code', ['WK2', 'WR2'])
            ->where('permission_name', 'mahasiswa.view')
            ->delete();
    }
};
