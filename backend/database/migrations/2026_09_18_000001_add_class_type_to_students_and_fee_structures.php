<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table): void {
            $table->string('class_type', 20)->default('REGULER')->after('current_semester');
            $table->index('class_type');
        });

        Schema::table('fee_structures', function (Blueprint $table): void {
            $table->string('class_type', 20)->nullable()->after('academic_level');
            $table->index(['fee_type_id', 'class_type'], 'fee_struct_class_idx');
        });
    }

    public function down(): void
    {
        Schema::table('fee_structures', function (Blueprint $table): void {
            $table->dropIndex('fee_struct_class_idx');
            $table->dropColumn('class_type');
        });

        Schema::table('students', function (Blueprint $table): void {
            $table->dropIndex('students_class_type_index');
            $table->dropColumn('class_type');
        });
    }
};
