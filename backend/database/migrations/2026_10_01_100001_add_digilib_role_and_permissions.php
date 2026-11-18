<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private array $permissions = [
        'digilib.borrow',
        'digilib.books.manage',
        'digilib.circulation.manage',
        'digilib.repository.manage',
        'digilib.procurement.manage',
        'digilib.reports.view',
        'digilib.settings.manage',
        'digilib.visits.manage',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach ($this->permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
        foreach (['SUPER_ADMIN', 'DOSEN', 'MAHASISWA', 'ALUMNI'] as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        }
        $role = Role::firstOrCreate(['name' => 'ADMIN_DIGILIB', 'guard_name' => 'web']);
        $role->syncPermissions($this->permissions);
        Role::findByName('SUPER_ADMIN', 'web')->givePermissionTo($this->permissions);
        foreach (['DOSEN', 'MAHASISWA', 'ALUMNI'] as $roleName) {
            Role::findByName($roleName, 'web')->givePermissionTo('digilib.borrow');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $role = Role::where('name', 'ADMIN_DIGILIB')->where('guard_name', 'web')->first();
        $role?->delete();
        DB::table('role_has_permissions')->whereIn(
            'permission_id',
            Permission::whereIn('name', $this->permissions)->pluck('id')
        )->delete();
        Permission::whereIn('name', $this->permissions)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
