<?php

namespace Tests\Unit;

use App\Exports\StudentAdvisorExport;
use App\Exports\TemplateExport;
use App\Imports\StudentAdvisorImport;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

class StudentAdvisorImportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('students', function (Blueprint $table): void {
            $table->id();
            $table->string('nim')->unique();
            $table->string('name');
            $table->unsignedBigInteger('study_program_id');
            $table->unsignedBigInteger('advisor_id')->nullable();
            $table->string('status')->default('Aktif');
            $table->timestamps();
        });
        Schema::create('lecturers', function (Blueprint $table): void {
            $table->id();
            $table->string('nidn')->nullable();
            $table->string('full_name');
        });

        DB::table('students')->insert([
            ['id' => 1, 'nim' => 'M001', 'name' => 'Mahasiswa Satu', 'study_program_id' => 10, 'advisor_id' => null, 'status' => 'Aktif', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'nim' => 'M002', 'name' => 'Mahasiswa Dua', 'study_program_id' => 20, 'advisor_id' => 9, 'status' => 'Aktif', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('lecturers')->insert([
            ['id' => 9, 'nidn' => '0011223344', 'full_name' => 'Dosen Satu'],
            ['id' => 10, 'nidn' => '0099887766', 'full_name' => 'Dosen Dua'],
        ]);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('lecturers');
        Schema::dropIfExists('students');

        parent::tearDown();
    }

    public function test_import_assigns_and_clears_advisors_using_only_student_and_lecturer_identifiers(): void
    {
        $import = new StudentAdvisorImport;
        $import->collection(new Collection([
            ['nim' => 'M001', 'nidn_dosen_pa' => '0011223344'],
            ['nim' => 'M002', 'nidn_dosen_pa' => ''],
        ]));

        $this->assertSame(2, $import->updatedCount());
        $this->assertSame(9, DB::table('students')->where('nim', 'M001')->value('advisor_id'));
        $this->assertNull(DB::table('students')->where('nim', 'M002')->value('advisor_id'));
        $this->assertSame([], $import->errors());
    }

    public function test_import_restricts_students_to_managed_programs_and_reports_unknown_identifiers(): void
    {
        $import = new StudentAdvisorImport([10]);
        $import->collection(new Collection([
            ['nim' => 'M002', 'nidn_dosen_pa' => '0099887766'],
            ['nim' => 'M001', 'nidn_dosen_pa' => '9999999999'],
        ]));

        $this->assertSame(0, $import->updatedCount());
        $this->assertSame(9, DB::table('students')->where('nim', 'M002')->value('advisor_id'));
        $this->assertCount(2, $import->errors());
    }

    public function test_import_rejects_workbooks_without_required_columns(): void
    {
        $import = new StudentAdvisorImport;
        $import->collection(new Collection([
            ['nim' => 'M001', 'dosen_pa' => '0011223344'],
        ]));

        $this->assertSame(0, $import->updatedCount());
        $this->assertStringContainsString('Format kolom tidak sesuai', $import->errors()[0]);
    }

    public function test_export_and_template_share_the_advisor_assignment_columns(): void
    {
        $headers = [
            'NIM',
            'Nama Mahasiswa',
            'Kode Prodi',
            'Nama Prodi',
            'NIDN Dosen PA',
            'Nama Dosen PA',
        ];

        $this->assertSame($headers, (new StudentAdvisorExport)->headings());
        $template = new TemplateExport('student-advisors');
        $this->assertSame($headers, $template->headings());
        $this->assertCount(count($headers), $template->array()[0]);
    }

    public function test_csv_template_headings_are_recognized_by_the_excel_importer(): void
    {
        $file = UploadedFile::fake()->createWithContent(
            'dosen-pa.csv',
            "NIM,Nama Mahasiswa,Kode Prodi,Nama Prodi,NIDN Dosen PA,Nama Dosen PA\nM001,Nama,P1,Prodi,0011223344,Dosen\n"
        );
        $import = new StudentAdvisorImport;

        Excel::import($import, $file);

        $this->assertSame(1, $import->updatedCount());
        $this->assertSame(9, DB::table('students')->where('nim', 'M001')->value('advisor_id'));
        $this->assertSame([], $import->errors());
    }
}
