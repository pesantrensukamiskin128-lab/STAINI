<?php

namespace Tests\Unit;

use App\Exports\StudentExport;
use App\Exports\TemplateExport;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\TestCase;

class StudentExportTest extends TestCase
{
    public function test_student_import_template_uses_the_export_headings_and_text_identifier_columns(): void
    {
        $export = new StudentExport;
        $template = new TemplateExport('students');

        $this->assertSame($export->headings(), $template->headings());
        $this->assertCount(71, $export->headings());
        $this->assertCount(count($export->headings()), $template->array()[0]);
        $this->assertSame([
            'Golongan Darah', 'Kota/Kabupaten', 'Provinsi',
            'No HP Ayah', 'Email Ayah', 'Ayah Masih Hidup',
            'No HP Ibu', 'Email Ibu', 'Ibu Masih Hidup',
            'No HP Wali', 'Email Wali', 'Wali Masih Hidup',
            'Nama Sekolah SMA/MA/SMK', 'Alamat Sekolah', 'Jurusan Sekolah',
            'Tahun Lulus', 'Nomor Ijazah',
        ], array_slice($export->headings(), 54));
        $this->assertSame('@', $template->columnFormats()['F']);
        $this->assertSame('@', $template->columnFormats()['BF']);
        $this->assertSame('@', $template->columnFormats()['BG']);
        $this->assertSame('@', $template->columnFormats()['BS']);

        $sheet = (new Spreadsheet)->getActiveSheet();
        $cell = $sheet->getCell('F2');
        $this->assertTrue($template->bindValue($cell, '0012345678901234'));
        $this->assertSame(DataType::TYPE_STRING, $cell->getDataType());
        $this->assertSame('0012345678901234', $cell->getValue());
    }

    public function test_identifier_columns_are_written_as_exact_text_values(): void
    {
        $export = new StudentExport;
        $sheet = (new Spreadsheet)->getActiveSheet();
        $values = [
            'A2' => '0012345678',
            'F2' => '3201234567890123',
            'H2' => '0012345678',
            'J2' => '001234567890123',
            'N2' => '202601',
            'P2' => '01',
            'Q2' => '02',
            'U2' => '00401',
            'X2' => '0221234567',
            'Y2' => '081234567890',
            'AB2' => '00123456789',
            'AC2' => '3201234567890123',
            'AI2' => '3209876543210123',
            'BF2' => '081234567890',
            'BG2' => 'person@example.test',
            'BS2' => 'DN-001234',
        ];

        foreach ($values as $coordinate => $value) {
            $cell = $sheet->getCell($coordinate);
            $this->assertTrue($export->bindValue($cell, $value));
            $this->assertSame(DataType::TYPE_STRING, $cell->getDataType(), $coordinate);
            $this->assertSame($value, $cell->getValue(), $coordinate);
        }

        $formats = $export->columnFormats();
        $this->assertSame('@', $formats['F']);
        $this->assertSame('@', $formats['H']);
        $this->assertSame('@', $formats['Y']);
        $this->assertSame('@', $formats['BF']);
        $this->assertSame('@', $formats['BS']);
    }
}
