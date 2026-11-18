<?php

namespace App\Exports;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithDrawings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

class ClassParticipantsExport implements FromArray, WithColumnWidths, WithDrawings, WithStyles
{
    private array $temporaryFiles = [];

    public function __construct(
        private readonly object $class,
        private readonly Collection $students,
        private readonly Collection $journals,
        private readonly string $verifyUrl,
        private readonly ?object $institution,
        private readonly string $qrHeadLecturer,
        private readonly string $qrLecturer,
        private readonly string $qrVerification,
    ) {}

    public function array(): array
    {
        $rows = [
            [$this->institution?->name ?? ''],
            ['PRESENSI TATAP MUKA PERKULIAHAN'],
            ['Mata Kuliah', $this->courseName(), '', 'SKS', $this->class->course?->credits ?? '-'],
            ['Semester', $this->class->semester?->name ?? '-', '', 'Kelas', $this->class->name ?? '-'],
            ['Fakultas', $this->class->studyProgram?->faculty?->name ?? '-', '', 'Program Studi', $this->class->studyProgram?->name ?? '-'],
            ['Dosen Pengampu', $this->lecturerName()],
            [],
            $this->headers(),
        ];

        foreach ($this->attendanceRows() as $row) {
            $rows[] = $row;
        }

        $rows[] = [];
        $rows[] = $this->footerRow('Ketua Program Studi', '', 'Dosen Pengampu');
        $rows[] = $this->footerRow('', '', '');
        $rows[] = $this->footerRow('', $this->headLecturerName(), '', $this->lecturerName());
        $rows[] = [];
        $rows[] = ['Verifikasi dokumen'];
        $rows[] = ['URL Verifikasi', $this->verifyUrl];
        $rows[] = ['H = Hadir, S = Sakit, I = Izin, A = Alpa. Persentase hadir dihitung dari pertemuan selesai; presensi yang belum tercatat ditampilkan kosong.'];

        return $rows;
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    public function attendanceRows(): array
    {
        $attendanceByStudent = [];
        $completedMeetings = [];

        foreach ($this->journals as $journal) {
            if ($journal->status === 'CANCELLED') {
                continue;
            }

            $meetingNumber = (int) $journal->meeting_number;
            if ($journal->status === 'COMPLETED') {
                $completedMeetings[$meetingNumber] = true;
            }

            foreach ($journal->attendances ?? [] as $attendance) {
                $attendanceByStudent[$attendance->student_id][$meetingNumber] = $attendance->status;
            }
        }

        $completedMeetingCount = count($completedMeetings);

        return $this->students->values()->map(function ($student, $index) use ($attendanceByStudent, $completedMeetings, $completedMeetingCount): array {
            $statuses = $attendanceByStudent[$student->id] ?? [];
            $attendanceCells = [];
            foreach (range(1, 16) as $meetingNumber) {
                $attendanceCells[] = match ($statuses[$meetingNumber] ?? null) {
                    'HADIR' => 'H',
                    'SAKIT' => 'S',
                    'IZIN' => 'I',
                    'ALFA' => 'A',
                    default => '',
                };
            }

            $knownStatuses = array_values($statuses);
            $completedStatuses = array_intersect_key($statuses, $completedMeetings);
            $presentCount = count(array_filter($completedStatuses, fn ($status) => $status === 'HADIR'));
            $sickCount = count(array_filter($knownStatuses, fn ($status) => $status === 'SAKIT'));
            $excusedCount = count(array_filter($knownStatuses, fn ($status) => $status === 'IZIN'));
            $absentCount = count(array_filter($knownStatuses, fn ($status) => $status === 'ALFA'));
            $attendancePercentage = $completedMeetingCount > 0
                ? number_format(($presentCount / $completedMeetingCount) * 100, 1, ',', '.').'%'
                : '-';

            return [
                $index + 1,
                $student->nim ?? '-',
                $student->name ?? '-',
                ...$attendanceCells,
                $sickCount,
                $excusedCount,
                $absentCount,
                $attendancePercentage,
            ];
        })->all();
    }

    /**
     * @return array<int, string>
     */
    public function headers(): array
    {
        $journalsByMeeting = $this->journals->reject(fn ($journal) => $journal->status === 'CANCELLED')->keyBy('meeting_number');
        $headers = ['No', 'NPM', 'Nama Mahasiswa'];

        foreach (range(1, 16) as $meetingNumber) {
            $journal = $journalsByMeeting->get($meetingNumber);
            $date = $journal?->meeting_date?->format('d/m')
                ?? ($journal?->meeting_date ? date('d/m', strtotime((string) $journal->meeting_date)) : '');
            $headers[] = 'P'.$meetingNumber.($date ? "\n".$date : '');
        }

        return [...$headers, 'Sakit', 'Izin', 'Alpa', 'Hadir (%)'];
    }

    public function verificationUrl(): string
    {
        return $this->verifyUrl;
    }

    public function qrHeadLecturer(): string
    {
        return $this->qrHeadLecturer;
    }

    public function qrLecturer(): string
    {
        return $this->qrLecturer;
    }

    public function qrVerification(): string
    {
        return $this->qrVerification;
    }

    public function columnWidths(): array
    {
        $widths = ['A' => 5, 'B' => 15, 'C' => 28];
        foreach (range('D', 'S') as $column) {
            $widths[$column] = 6;
        }
        foreach (range('T', 'W') as $column) {
            $widths[$column] = 11;
        }

        return $widths;
    }

    public function drawings(): array
    {
        $signatureQrRow = 11 + $this->students->count();
        $verificationQrRow = 14 + $this->students->count();
        $verificationColumn = 'W';

        return [
            $this->makeDrawing('QR tanda tangan Ketua Program Studi', $this->qrHeadLecturer, 'A'.$signatureQrRow),
            $this->makeDrawing('QR tanda tangan Dosen Pengampu', $this->qrLecturer, 'N'.$signatureQrRow),
            $this->makeDrawing('QR verifikasi dokumen', $this->qrVerification, $verificationColumn.$verificationQrRow),
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        $lastRow = 8 + $this->students->count();
        $signatureRow = 10 + $this->students->count();
        $signatureQrRow = 11 + $this->students->count();
        $verificationQrRow = 14 + $this->students->count();
        $sheet->getRowDimension(8)->setRowHeight(38);
        $sheet->getRowDimension($signatureQrRow)->setRowHeight(62);
        $sheet->getRowDimension($verificationQrRow)->setRowHeight(62);
        $sheet->mergeCells('A1:W1');
        $sheet->mergeCells('A2:W2');
        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(PageSetup::PAPERSIZE_A3)
            ->setFitToWidth(1)
            ->setFitToHeight(0)
            ->setFitToPage(true)
            ->setRowsToRepeatAtTopByStartAndEnd(8, 8);

        $styles = [
            1 => ['font' => ['bold' => true, 'size' => 12], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]],
            2 => ['font' => ['bold' => true, 'size' => 15], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]],
            'A'.$signatureRow.':W'.($signatureQrRow + 1) => ['font' => ['bold' => true]],
        ];

        if ($this->students->isNotEmpty()) {
            $styles['A8:W'.$lastRow] = [
                'font' => ['size' => 10],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
                'borders' => ['allBorders' => ['borderStyle' => 'thin', 'color' => ['rgb' => '000000']]],
            ];
            $styles['A9:W'.$lastRow] = ['alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true]];
        }

        $styles['A8:W8'] = [
            'font' => ['bold' => true, 'size' => 10, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2563EB']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        ];

        return $styles;
    }

    public function __destruct()
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function makeDrawing(string $name, string $qrData, string $coordinates): Drawing
    {
        $image = $this->qrImageBytes($qrData);
        $path = tempnam(sys_get_temp_dir(), 'asc-presensi-');
        if ($path === false) {
            throw new RuntimeException('Gagal menyiapkan gambar QR untuk ekspor Excel.');
        }

        $this->temporaryFiles[] = $path;
        if (file_put_contents($path, $image) === false) {
            throw new RuntimeException('Gagal menyiapkan gambar QR untuk ekspor Excel.');
        }

        $drawing = new Drawing;
        $drawing->setName($name);
        $drawing->setDescription($name);
        $drawing->setPath($path);
        $drawing->setCoordinates($coordinates);
        $drawing->setHeight(58);

        return $drawing;
    }

    private function qrImageBytes(string $qrData): string
    {
        if (str_starts_with($qrData, 'data:image/png;base64,')) {
            $image = base64_decode(substr($qrData, strlen('data:image/png;base64,')), true);
        } else {
            $url = filter_var($qrData, FILTER_VALIDATE_URL) ? parse_url($qrData) : false;
            if (! $url || ($url['host'] ?? '') !== 'api.qrserver.com') {
                throw new RuntimeException('QR untuk ekspor Excel tidak memiliki format gambar yang valid.');
            }

            $response = Http::timeout(10)->get($qrData);
            if (! $response->successful() || ! str_starts_with($response->header('Content-Type') ?? '', 'image/png')) {
                throw new RuntimeException('Gagal mengunduh gambar QR untuk ekspor Excel.');
            }
            $image = $response->body();
        }

        if (! is_string($image) || @getimagesizefromstring($image) === false) {
            throw new RuntimeException('Gambar QR untuk ekspor Excel tidak valid.');
        }

        return $image;
    }

    private function footerRow(string $firstLabel, string $firstValue, string $secondLabel, string $secondValue = ''): array
    {
        $row = array_fill(0, 23, '');
        $row[0] = $firstLabel;
        $row[1] = $firstValue;
        $row[13] = $secondLabel;
        $row[14] = $secondValue;

        return $row;
    }

    private function courseName(): string
    {
        return trim(($this->class->course?->code ? $this->class->course->code.' - ' : '').($this->class->course?->name ?? '-'));
    }

    public function lecturerName(): string
    {
        $lecturer = $this->class->lecturer?->display_name ?? $this->class->lecturer?->full_name;
        if ($lecturer) {
            return $lecturer;
        }

        return ($this->class->schedules ?? collect())
            ->where('is_active', true)
            ->map(fn ($schedule) => $schedule->lecturer?->display_name ?? $schedule->lecturer?->full_name)
            ->filter()
            ->unique()
            ->implode(', ') ?: '-';
    }

    public function headLecturerName(): string
    {
        return $this->class->studyProgram?->headLecturer?->display_name
            ?? $this->class->studyProgram?->headLecturer?->full_name
            ?? '................................';
    }
}
