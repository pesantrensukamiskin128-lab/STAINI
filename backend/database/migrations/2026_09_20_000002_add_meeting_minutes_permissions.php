<?php
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
return new class extends Migration {
    public function up(): void {
        $permissions = ['notulen.view','notulen.create','notulen.edit','notulen.review','notulen.sign'];
        foreach ($permissions as $permission) Permission::firstOrCreate(['name'=>$permission,'guard_name'=>'web']);
        foreach (['SUPER_ADMIN','ADMIN_UMUM'] as $role) if ($r = Role::where('name',$role)->first()) $r->givePermissionTo($permissions);
        if ($r = Role::where('name','KEPALA_TU')->first()) $r->givePermissionTo(['notulen.view','notulen.review']);
        foreach (['KETUA','REKTOR','WK1','WR1','WK2','WR2','WK3','WR3','DEKAN','WADEK1','WADEK2','WADEK3','KAPRODI'] as $code) {
            \Illuminate\Support\Facades\DB::table('position_permissions')->insertOrIgnore(['position_code'=>$code,'permission_name'=>'notulen.sign','created_at'=>now(),'updated_at'=>now()]);
        }
    }
    public function down(): void { Permission::whereIn('name',['notulen.view','notulen.create','notulen.edit','notulen.review','notulen.sign'])->delete(); }
};
