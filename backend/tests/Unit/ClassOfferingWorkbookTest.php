<?php

namespace Tests\Unit;

use App\Exports\ClassOfferingExport;
use App\Http\Controllers\Api\ClassController;
use App\Imports\ClassOfferingImport;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ClassOfferingWorkbookTest extends TestCase
{
    public function test_template_contains_class_course_and_schedule_sheets(): void
    {
        $sheets = ClassOfferingExport::template()->sheets();

        $this->assertCount(3, $sheets);
        $this->assertSame(['Kelas', 'Mata Kuliah', 'Jadwal'], array_map(fn ($sheet) => $sheet->title(), $sheets));
        $this->assertContains('referensi_kelas', $sheets[0]->headings());
        $this->assertContains('konfirmasi_beda_sks', $sheets[1]->headings());
        $this->assertContains('jam_mulai', $sheets[2]->headings());
    }

    public function test_generated_template_can_be_read_by_the_class_offering_importer(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'asc-class-template-').'.xlsx';

        try {
            file_put_contents($path, Excel::raw(ClassOfferingExport::template(), ExcelWriter::XLSX));
            $import = new ClassOfferingImport;
            Excel::import($import, $path);

            $this->assertSame('KELAS-CONTOH-1', $import->classes->rows()->first()['referensi_kelas']);
            $this->assertCount(2, $import->courses->rows());
            $this->assertCount(2, $import->schedules->rows());
        } finally {
            @unlink($path);
        }
    }

    public function test_ordinary_lecturer_cannot_export_class_offerings(): void
    {
        $user = new class
        {
            public mixed $lecturer = null;

            public function hasAnyRole(array $roles): bool
            {
                return false;
            }
        };
        $request = Request::create('/api/classes/export');
        $request->setUserResolver(fn () => $user);

        try {
            app(ClassController::class)->export($request);
            $this->fail('Ekspor seharusnya ditolak.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }
}
