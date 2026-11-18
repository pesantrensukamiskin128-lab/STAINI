<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lecturer_work_authors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lecturer_work_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lecturer_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('author_order');
            $table->timestamps();

            $table->unique(['lecturer_work_id', 'lecturer_id']);
            $table->unique(['lecturer_work_id', 'student_id']);
            $table->index(['lecturer_work_id', 'author_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lecturer_work_authors');
    }
};
