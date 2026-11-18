<?php

namespace Tests\Feature;

use App\Exports\LecturerExport;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LecturerExportScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('study_programs', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
        });

        Schema::create('lecturers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('study_program_id')->nullable();
            $table->string('full_name');
        });

        DB::table('study_programs')->insert([
            ['id' => 1, 'name' => 'Program A'],
            ['id' => 2, 'name' => 'Program B'],
        ]);
        DB::table('lecturers')->insert([
            ['id' => 1, 'study_program_id' => 1, 'full_name' => 'Lecturer A'],
            ['id' => 2, 'study_program_id' => 2, 'full_name' => 'Lecturer B'],
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('lecturers');
        Schema::dropIfExists('study_programs');

        parent::tearDown();
    }

    public function test_program_scope_limits_export_and_cannot_be_overridden_by_filter(): void
    {
        $scopedExport = new LecturerExport(accessibleStudyProgramIds: [1]);

        $this->assertSame([1], $scopedExport->query()->pluck('id')->all());
        $this->assertSame(
            [],
            (new LecturerExport(studyProgramId: 2, accessibleStudyProgramIds: [1]))->query()->pluck('id')->all()
        );
        $this->assertSame([], (new LecturerExport(accessibleStudyProgramIds: []))->query()->pluck('id')->all());
    }

    public function test_unrestricted_export_can_include_all_programs(): void
    {
        $this->assertSame([1, 2], (new LecturerExport)->query()->pluck('id')->all());
    }
}
