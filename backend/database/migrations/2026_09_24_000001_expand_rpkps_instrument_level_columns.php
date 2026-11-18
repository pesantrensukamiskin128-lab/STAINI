<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rpkps_assessment_instruments', function (Blueprint $table): void {
            $table->text('cognitive_level')->nullable()->change();
            $table->text('affective_level')->nullable()->change();
            $table->text('psychomotor_level')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('rpkps_assessment_instruments', function (Blueprint $table): void {
            $table->string('cognitive_level', 30)->nullable()->change();
            $table->string('affective_level', 30)->nullable()->change();
            $table->string('psychomotor_level', 30)->nullable()->change();
        });
    }
};
