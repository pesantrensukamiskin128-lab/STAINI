<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rpkps', function (Blueprint $table): void {
            $table->text('final_grade_formula')->nullable()->after('assessment_components');
        });

        Schema::create('rpkps_grade_scales', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rpkps_id')->constrained('rpkps')->cascadeOnDelete();
            $table->decimal('min_score', 5, 2);
            $table->decimal('max_score', 5, 2);
            $table->string('letter', 5);
            $table->decimal('grade_point', 3, 2);
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rpkps_grade_scales');
        Schema::table('rpkps', function (Blueprint $table): void {
            $table->dropColumn('final_grade_formula');
        });
    }
};
