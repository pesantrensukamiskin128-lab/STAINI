<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration {
    public function up(): void
    {
        $permission = Permission::firstOrCreate(['name' => 'notulen.view', 'guard_name' => 'web']);
        if ($role = Role::where('name', 'DOSEN')->first()) {
            $role->givePermissionTo($permission);
        }
    }

    public function down(): void
    {
        if ($role = Role::where('name', 'DOSEN')->first()) {
            $role->revokePermissionTo('notulen.view');
        }
    }
};
