<?php

namespace Tests\Feature;

use App\Exports\CurriculumObeWorkbookExport;
use App\Imports\CurriculumObeWorkbookImport;
use App\Models\Curriculum;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

class CurriculumObeWorkbookImportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('curriculums', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('study_program_id');
            $table->string('code');
            $table->string('name');
            $table->unsignedInteger('year');
            $table->string('status');
            $table->timestamps();
        });
        Schema::create('graduate_profiles', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('curriculum_id');
            $table->string('code');
            $table->string('name');
            $table->text('description')->nullable();
            $table->integer('order')->default(0);
            $table->timestamps();
        });
        Schema::create('learning_outcomes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('curriculum_id');
            $table->string('code');
            $table->string('category', 50)->nullable();
            $table->text('description');
            $table->integer('order')->default(0);
            $table->timestamps();
        });
        Schema::create('cpl_graduate_profile', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('learning_outcome_id');
            $table->unsignedBigInteger('graduate_profile_id');
            $table->timestamps();
        });
        Schema::create('courses', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('study_program_id');
            $table->string('code');
            $table->string('name');
        });
        Schema::create('curriculum_courses', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('curriculum_id');
            $table->unsignedBigInteger('course_id');
            $table->unsignedInteger('semester');
            $table->boolean('is_required');
            $table->timestamps();
            $table->unique(['curriculum_id', 'course_id']);
        });
        Schema::create('cpl_course_mappings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('learning_outcome_id');
            $table->unsignedBigInteger('course_id');
            $table->string('support_level');
            $table->timestamps();
            $table->unique(['learning_outcome_id', 'course_id']);
        });

        DB::table('curriculums')->insert([
            'id' => 1,
            'study_program_id' => 4,
            'code' => 'KUR-2026',
            'name' => 'Kurikulum 2026',
            'year' => 2026,
            'status' => 'Draft',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('courses')->insert([
            'id' => 7,
            'study_program_id' => 4,
            'code' => 'MK-01',
            'name' => 'Mata Kuliah Contoh',
        ]);
    }

    protected function tearDown(): void
    {
        foreach ([
            'cpl_course_mappings',
            'curriculum_courses',
            'courses',
            'cpl_graduate_profile',
            'learning_outcomes',
            'graduate_profiles',
            'curriculums',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_import_merges_profiles_outcomes_courses_and_mappings(): void
    {
        $counts = $this->importWorkbook();

        $this->assertSame([
            'profiles' => 1,
            'learning_outcomes' => 1,
            'courses' => 1,
            'mappings' => 1,
        ], $counts);
        $this->assertDatabaseHas('graduate_profiles', ['curriculum_id' => 1, 'code' => 'PL-01']);
        $this->assertDatabaseHas('learning_outcomes', ['curriculum_id' => 1, 'code' => 'CPL-01', 'category' => 'Sikap']);
        $this->assertDatabaseHas('cpl_graduate_profile', ['learning_outcome_id' => 1, 'graduate_profile_id' => 1]);
        $this->assertDatabaseHas('curriculum_courses', ['curriculum_id' => 1, 'course_id' => 7, 'semester' => 2, 'is_required' => true]);
        $this->assertDatabaseHas('cpl_course_mappings', ['learning_outcome_id' => 1, 'course_id' => 7, 'support_level' => 'Tinggi']);
    }

    public function test_invalid_workbook_does_not_partially_import_rows(): void
    {
        try {
            $this->importWorkbook('MK-TIDAK-ADA');
            $this->fail('Expected validation to reject an unknown course.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors()['file']);
            $this->assertDatabaseCount('graduate_profiles', 0);
            $this->assertDatabaseCount('learning_outcomes', 0);
            $this->assertDatabaseCount('curriculum_courses', 0);
        }
    }

    public function test_template_workbook_contains_all_import_sheets_and_headers(): void
    {
        Storage::fake('local');
        $path = 'template-detail-kurikulum.xlsx';

        Excel::store(new CurriculumObeWorkbookExport(template: true), $path, 'local');
        $sheets = Excel::toArray([], $path, 'local');

        $this->assertCount(5, $sheets);
        $this->assertSame(['code', 'name', 'description', 'order'], $sheets[1][0]);
        $this->assertSame(['code', 'category', 'description', 'graduate_profile_codes', 'order'], $sheets[2][0]);
        $this->assertSame(['course_code', 'semester', 'required'], $sheets[3][0]);
        $this->assertSame(['cpl_code', 'course_code', 'mapped', 'level'], $sheets[4][0]);
    }

    private function importWorkbook(string $courseCode = 'MK-01'): array
    {
        $sheets = [
            [['Template detail kurikulum OBE']],
            [['code', 'name', 'description', 'order'], ['PL-01', 'Profil Contoh', 'Deskripsi profil', 1]],
            [['code', 'category', 'description', 'graduate_profile_codes', 'order'], ['CPL-01', 'Sikap', 'Deskripsi CPL', 'PL-01', 1]],
            [['course_code', 'semester', 'required'], [$courseCode, 2, 'Ya']],
            [['cpl_code', 'course_code', 'mapped', 'level'], ['CPL-01', $courseCode, 'Ya', 'Tinggi']],
        ];

        return (new CurriculumObeWorkbookImport(Curriculum::findOrFail(1), $sheets))->import();
    }
}
