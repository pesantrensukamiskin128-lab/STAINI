<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_profiles', function (Blueprint $table): void {
            $table->string('admission_path', 50)->nullable();
            $table->string('npwp', 30)->nullable();
            $table->string('registration_type', 50)->nullable();
            $table->date('enrollment_date')->nullable();
            $table->string('start_semester', 10)->nullable();
            $table->string('residence_type', 50)->nullable();
            $table->string('transport_mode', 50)->nullable();
            $table->boolean('kps_recipient')->nullable();
            $table->string('kps_number', 50)->nullable();
            $table->string('financing_type', 50)->nullable();
            $table->unsignedInteger('recognized_credits')->nullable();
            $table->string('origin_institution_code', 30)->nullable();
            $table->string('origin_institution_name')->nullable();
            $table->string('origin_program_code', 30)->nullable();
            $table->string('origin_program_name')->nullable();
            $table->decimal('entry_fee', 14, 0)->nullable();
        });

        Schema::table('student_addresses', function (Blueprint $table): void {
            $table->string('rt', 5)->nullable();
            $table->string('rw', 5)->nullable();
            $table->string('hamlet', 100)->nullable();
        });

        Schema::table('students', function (Blueprint $table): void {
            $table->string('home_phone', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('students', fn (Blueprint $table) => $table->dropColumn('home_phone'));
        Schema::table('student_addresses', fn (Blueprint $table) => $table->dropColumn(['rt', 'rw', 'hamlet']));
        Schema::table('student_profiles', function (Blueprint $table): void {
            $table->dropColumn([
                'admission_path', 'npwp', 'registration_type', 'enrollment_date',
                'start_semester', 'residence_type', 'transport_mode', 'kps_recipient',
                'kps_number', 'financing_type', 'recognized_credits',
                'origin_institution_code', 'origin_institution_name',
                'origin_program_code', 'origin_program_name', 'entry_fee',
            ]);
        });
    }
};
