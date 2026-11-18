<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('honor_rates', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('category', 20);
            $table->string('unit', 20);
            $table->string('position_code', 100)->nullable();
            $table->string('delivery_mode', 10)->nullable();
            $table->decimal('amount', 14, 0);
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['category', 'position_code', 'delivery_mode', 'is_active'], 'honor_rates_lookup_idx');
            $table->index(['effective_from', 'effective_until'], 'honor_rates_effective_idx');
        });

        Schema::create('honor_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lecturer_id')->nullable()->constrained('lecturers')->restrictOnDelete();
            $table->foreignId('honor_rate_id')->constrained('honor_rates')->restrictOnDelete();
            $table->foreignId('semester_id')->nullable()->constrained('semesters')->nullOnDelete();
            $table->string('source_key', 100)->unique();
            $table->string('source_type', 30);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('reference_number', 100)->nullable();
            $table->string('description');
            $table->date('activity_date');
            $table->decimal('quantity', 10, 2);
            $table->decimal('unit_rate_snapshot', 14, 0);
            $table->decimal('amount_snapshot', 14, 0);
            $table->string('status', 15)->default('PENDING');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('verification_note')->nullable();
            $table->timestamps();

            $table->index(['lecturer_id', 'activity_date', 'status'], 'honor_activities_period_idx');
            $table->index(['source_type', 'source_id']);
            $table->unique(['lecturer_id', 'reference_number'], 'honor_activity_reference_unique');
            $table->index(['honor_rate_id', 'activity_date']);
        });

        Schema::create('honor_payrolls', function (Blueprint $table): void {
            $table->id();
            $table->string('period_key', 30)->unique();
            $table->string('period_type', 10);
            $table->foreignId('semester_id')->nullable()->constrained('semesters')->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 15)->default('DRAFT');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_reference')->nullable();
            $table->timestamps();

            $table->index(['period_type', 'status']);
            $table->index(['period_start', 'period_end']);
        });

        Schema::create('honor_payroll_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('honor_payroll_id')->constrained('honor_payrolls')->cascadeOnDelete();
            $table->foreignId('lecturer_id')->nullable()->constrained('lecturers')->restrictOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('staff')->restrictOnDelete();
            $table->foreignId('honor_rate_id')->nullable()->constrained('honor_rates')->nullOnDelete();
            $table->string('source_key', 100);
            $table->string('description');
            $table->date('activity_date');
            $table->decimal('quantity', 10, 2);
            $table->decimal('unit_rate_snapshot', 14, 0);
            $table->decimal('amount_snapshot', 14, 0);
            $table->timestamps();

            $table->unique(['honor_payroll_id', 'source_key'], 'honor_payroll_items_source_unique');
            $table->index(['honor_payroll_id', 'lecturer_id']);
            $table->index(['honor_payroll_id', 'staff_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('honor_payroll_items');
        Schema::dropIfExists('honor_payrolls');
        Schema::dropIfExists('honor_activities');
        Schema::dropIfExists('honor_rates');
    }
};
