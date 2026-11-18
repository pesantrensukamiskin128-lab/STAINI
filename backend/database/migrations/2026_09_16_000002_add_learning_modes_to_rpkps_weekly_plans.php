<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rpkps_weekly_plans', function (Blueprint $table): void {
            $table->text('offline_learning')->nullable()->after('methods');
            $table->text('online_learning')->nullable()->after('offline_learning');
        });
    }

    public function down(): void
    {
        Schema::table('rpkps_weekly_plans', function (Blueprint $table): void {
            $table->dropColumn(['offline_learning', 'online_learning']);
        });
    }
};
