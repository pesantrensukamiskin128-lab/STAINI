<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignment_sub_cpmk', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('assignment_id')->constrained('assignments')->cascadeOnDelete();
            $table->foreignId('rpkps_sub_cpmk_id')->constrained('rpkps_sub_cpmks')->cascadeOnDelete();
            $table->unique(['assignment_id', 'rpkps_sub_cpmk_id'], 'assignment_sub_cpmk_unique');
        });

        Schema::create('exam_sub_cpmk', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('exam_id')->constrained('exams')->cascadeOnDelete();
            $table->foreignId('rpkps_sub_cpmk_id')->constrained('rpkps_sub_cpmks')->cascadeOnDelete();
            $table->unique(['exam_id', 'rpkps_sub_cpmk_id'], 'exam_sub_cpmk_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_sub_cpmk');
        Schema::dropIfExists('assignment_sub_cpmk');
    }
};
