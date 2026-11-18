<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rpkps_assessment_instruments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rpkps_id')->constrained('rpkps')->cascadeOnDelete();
            $table->foreignId('rpkps_assessment_id')->nullable()->constrained('rpkps_assessments')->nullOnDelete();
            $table->text('sub_cpmk')->nullable();
            $table->string('instrument_name');
            $table->string('instrument_type', 50)->nullable();
            $table->string('technique', 100)->nullable();
            $table->string('cognitive_level', 30)->nullable();
            $table->string('affective_level', 30)->nullable();
            $table->string('psychomotor_level', 30)->nullable();
            $table->string('item_numbers', 100)->nullable();
            $table->unsignedTinyInteger('weight')->default(0);
            $table->string('timing', 100)->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
        });

        Schema::table('rpkps_rubrics', function (Blueprint $table): void {
            $table->string('title')->nullable()->after('rpkps_assessment_id');
            $table->unsignedTinyInteger('weight')->nullable()->after('criteria');
            $table->text('very_poor')->nullable()->after('poor');
        });
    }

    public function down(): void
    {
        Schema::table('rpkps_rubrics', function (Blueprint $table): void {
            $table->dropColumn(['title', 'weight', 'very_poor']);
        });
        Schema::dropIfExists('rpkps_assessment_instruments');
    }
};
