<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('honor_payroll_deductions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('honor_payroll_id')->constrained('honor_payrolls')->cascadeOnDelete();
            $table->foreignId('lecturer_id')->nullable()->constrained('lecturers')->restrictOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('staff')->restrictOnDelete();
            $table->string('deduction_type', 10);
            $table->string('description');
            $table->unsignedBigInteger('amount');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['honor_payroll_id', 'lecturer_id'], 'honor_deductions_payroll_lecturer_idx');
            $table->index(['honor_payroll_id', 'staff_id'], 'honor_deductions_payroll_staff_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('honor_payroll_deductions');
    }
};
