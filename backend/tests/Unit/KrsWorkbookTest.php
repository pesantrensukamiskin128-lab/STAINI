<?php

namespace Tests\Unit;

use App\Exports\TemplateExport;
use App\Imports\KrsImport;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

class KrsWorkbookTest extends TestCase
{
    public function test_krs_template_can_be_read_by_the_bulk_importer(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'asc-krs-template-').'.xlsx';

        try {
            file_put_contents($path, Excel::raw(new TemplateExport('krs'), ExcelWriter::XLSX));
            $import = new KrsImport;
            Excel::import($import, $path);

            $this->assertCount(2, $import->rows());
            $this->assertSame('2026001001', (string) $import->rows()->first()['nim']);
            $this->assertSame('PAI101', $import->rows()->first()['kode_mata_kuliah']);
            $this->assertSame(101, $import->rows()->first()['id_kelas']);
        } finally {
            @unlink($path);
        }
    }
}
