<?php

namespace Tests\Unit;

use App\Exports\ClassParticipantsExport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PHPUnit\Framework\TestCase;

class ClassParticipantsExportTest extends TestCase
{
    public function test_export_builds_attendance_rows_and_keeps_unrecorded_statuses_blank(): void
    {
        $class = $this->classData();
        $students = new Collection([
            (object) ['id' => 1, 'nim' => '2026001', 'name' => 'Mahasiswa Satu'],
            (object) ['id' => 2, 'nim' => '2026002', 'name' => 'Mahasiswa Dua'],
        ]);
        $journals = new Collection([
            $this->journal(1, 'COMPLETED', '2026-09-01', [
                (object) ['student_id' => 1, 'status' => 'HADIR'],
            ]),
            $this->journal(2, 'COMPLETED', '2026-09-08', [
                (object) ['student_id' => 1, 'status' => 'SAKIT'],
                (object) ['student_id' => 2, 'status' => 'ALFA'],
            ]),
            $this->journal(3, 'PLANNED', '2026-09-15', []),
            $this->journal(4, 'CANCELLED', '2026-09-22', [
                (object) ['student_id' => 1, 'status' => 'HADIR'],
            ]),
        ]);

        $export = $this->export($class, $students, $journals);
        $rows = $export->attendanceRows();

        $this->assertSame(23, count($rows[0]));
        $this->assertSame([1, '2026001', 'Mahasiswa Satu', 'H', 'S'], array_slice($rows[0], 0, 5));
        $this->assertSame([1, 0, 0, '50,0%'], array_slice($rows[0], 19));
        $this->assertSame([2, '2026002', 'Mahasiswa Dua', '', 'A'], array_slice($rows[1], 0, 5));
        $this->assertSame([0, 0, 1, '0,0%'], array_slice($rows[1], 19));
        $this->assertSame('', $rows[0][5]);
        $this->assertSame('', $rows[0][6]);
        $this->assertSame('P1'."\n".'01/09', $export->headers()[3]);
        $this->assertSame(10, $export->styles(new Worksheet(null, 'Test'))['A8:W10']['font']['size']);
    }

    public function test_attendance_percentage_is_unavailable_when_no_meeting_is_completed(): void
    {
        $export = $this->export(
            $this->classData(),
            new Collection([(object) ['id' => 1, 'nim' => '2026001', 'name' => 'Mahasiswa Satu']]),
            new Collection([$this->journal(1, 'PLANNED', '2026-09-01', [])]),
        );

        $this->assertSame('-', $export->attendanceRows()[0][22]);
    }

    public function test_excel_export_places_both_signatures_and_verification_qrs(): void
    {
        $export = $this->export(
            $this->classData(),
            new Collection([(object) ['id' => 1, 'nim' => '2026001', 'name' => 'Mahasiswa Satu']]),
            new Collection,
        );

        $drawings = $export->drawings();

        $this->assertSame(['A12', 'N12', 'W15'], array_map(fn ($drawing) => $drawing->getCoordinates(), $drawings));

        $temporaryFile = tempnam(sys_get_temp_dir(), 'asc-presensi-test-');
        $this->assertNotFalse($temporaryFile);
        $xlsxPath = $temporaryFile.'.xlsx';
        unlink($temporaryFile);

        try {
            $spreadsheet = new Spreadsheet;
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->fromArray($export->array(), null, 'A1');
            $export->styles($sheet);
            foreach ($drawings as $drawing) {
                $drawing->setWorksheet($sheet);
            }

            IOFactory::createWriter($spreadsheet, 'Xlsx')->save($xlsxPath);
            $reopened = IOFactory::load($xlsxPath);
            $this->assertCount(3, $reopened->getActiveSheet()->getDrawingCollection());
            $this->assertContains('A1:W1', $reopened->getActiveSheet()->getMergeCells());
            $this->assertContains('A2:W2', $reopened->getActiveSheet()->getMergeCells());
            $this->assertSame(PageSetup::ORIENTATION_LANDSCAPE, $reopened->getActiveSheet()->getPageSetup()->getOrientation());
            $this->assertSame(PageSetup::PAPERSIZE_A3, $reopened->getActiveSheet()->getPageSetup()->getPaperSize());
        } finally {
            if (is_file($xlsxPath)) {
                unlink($xlsxPath);
            }
        }
    }

    private function export(object $class, Collection $students, Collection $journals): ClassParticipantsExport
    {
        $qr = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jXioAAAAASUVORK5CYII=';

        return new ClassParticipantsExport(
            $class,
            $students,
            $journals,
            'https://example.test/verify',
            null,
            $qr,
            $qr,
            $qr,
        );
    }

    private function classData(): object
    {
        return (object) [
            'name' => 'A',
            'course' => (object) ['code' => 'MK101', 'name' => 'Pengantar', 'credits' => 3],
            'semester' => (object) ['name' => 'Ganjil 2026/2027'],
            'lecturer' => (object) ['display_name' => 'Dosen Pengampu', 'full_name' => 'Dosen Pengampu'],
            'studyProgram' => (object) [
                'name' => 'Sistem Informasi',
                'faculty' => (object) ['name' => 'Fakultas Sains'],
                'headLecturer' => (object) ['display_name' => 'Ketua Prodi', 'full_name' => 'Ketua Prodi'],
            ],
        ];
    }

    private function journal(int $number, string $status, string $date, array $attendances): object
    {
        return (object) [
            'meeting_number' => $number,
            'meeting_date' => Carbon::parse($date),
            'status' => $status,
            'attendances' => new Collection($attendances),
        ];
    }
}
