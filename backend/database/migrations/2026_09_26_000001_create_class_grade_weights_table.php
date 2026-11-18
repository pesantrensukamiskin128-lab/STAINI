<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_grade_weights', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('class_id')->unique()->constrained('classes')->cascadeOnDelete();
            $table->unsignedTinyInteger('assignment_weight')->default(40);
            $table->unsignedTinyInteger('exam_weight')->default(40);
            $table->unsignedTinyInteger('attendance_weight')->default(20);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_grade_weights');
    }
};
