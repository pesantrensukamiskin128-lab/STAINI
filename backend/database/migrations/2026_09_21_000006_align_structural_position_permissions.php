<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            'KETUA_LP2M' => [
                'karya.verify',
                'skripsi.view',
            ],
            'SEKRETARIS_LP2M' => [
                'karya.verify',
                'skripsi.view',
            ],
        ];

        foreach ($permissions as $positionCode => $permissionNames) {
            foreach ($permissionNames as $permissionName) {
                DB::table('position_permissions')->updateOrInsert(
                    [
                        'position_code' => $positionCode,
                        'permission_name' => $permissionName,
                    ],
                    [
                        'updated_at' => now(),
                        'created_at' => now(),
                    ],
                );
            }
        }
    }

    public function down(): void
    {
        DB::table('position_permissions')
            ->whereIn('position_code', ['KETUA_LP2M', 'SEKRETARIS_LP2M'])
            ->whereIn('permission_name', ['karya.verify', 'skripsi.view'])
            ->delete();
    }
};
