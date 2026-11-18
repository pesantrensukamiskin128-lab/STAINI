<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HonorSchemaMigrationTest extends TestCase
{
    public function test_honor_migrations_create_and_rollback_new_schema(): void
    {
        foreach ([
            'honor_payroll_deductions', 'honor_payroll_items', 'honor_payrolls', 'honor_activities', 'honor_rates',
            'lecture_journals', 'semesters', 'staff', 'lecturers', 'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::create('lecturers', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('staff', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('semesters', function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('lecture_journals', function (Blueprint $table): void {
            $table->id();
            $table->string('status');
            $table->date('meeting_date');
            $table->unsignedBigInteger('lecturer_id')->nullable();
        });
        \Illuminate\Support\Facades\DB::table('lecture_journals')->insert([
            ['id' => 1, 'status' => 'COMPLETED', 'meeting_date' => '2026-10-05'],
            ['id' => 2, 'status' => 'PLANNED', 'meeting_date' => '2026-10-12'],
        ]);

        $deliveryModeMigration = require database_path('migrations/2026_10_02_000001_add_delivery_mode_to_lecture_journals.php');
        $reviewMigration = require database_path('migrations/2026_10_08_000002_add_review_workflow_to_lecture_journals.php');
        $honorMigration = require database_path('migrations/2026_10_02_000002_create_lecturer_honor_tables.php');
        $deductionMigration = require database_path('migrations/2026_10_08_000001_create_honor_payroll_deductions_table.php');
        $deliveryModeMigration->up();
        $reviewMigration->up();
        $honorMigration->up();
        $deductionMigration->up();

        $this->assertTrue(Schema::hasColumn('lecture_journals', 'delivery_mode'));
        $this->assertTrue(Schema::hasColumn('lecture_journals', 'review_status'));
        $this->assertDatabaseHas('lecture_journals', ['id' => 1, 'review_status' => 'PENDING']);
        $this->assertDatabaseHas('lecture_journals', ['id' => 2, 'review_status' => 'NOT_SUBMITTED']);
        $this->assertTrue(Schema::hasColumn('honor_payroll_items', 'staff_id'));
        foreach (['honor_rates', 'honor_activities', 'honor_payrolls', 'honor_payroll_items', 'honor_payroll_deductions'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }

        $deductionMigration->down();
        $honorMigration->down();
        $reviewMigration->down();
        $deliveryModeMigration->down();
        $this->assertFalse(Schema::hasTable('honor_rates'));
        $this->assertFalse(Schema::hasColumn('lecture_journals', 'delivery_mode'));
        $this->assertFalse(Schema::hasColumn('lecture_journals', 'review_status'));
    }
}
