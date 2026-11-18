<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->string('delivery_mode', 20)->default('TATAP_MUKA')->after('type');
        });

        DB::table('courses')
            ->where(function ($query): void {
                $query->where('name', 'like', '%KKN%')
                    ->orWhere('name', 'like', '%MAGANG%')
                    ->orWhere('name', 'like', '%PEMAGANGAN%')
                    ->orWhere('name', 'like', '%KOMPREHENSIF%')
                    ->orWhere('name', 'like', '%MUNAQOSYAH%')
                    ->orWhere('name', 'like', '%MUNAQASYAH%');
            })
            ->update(['delivery_mode' => 'NON_TATAP_MUKA']);
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->dropColumn('delivery_mode');
        });
    }
};
