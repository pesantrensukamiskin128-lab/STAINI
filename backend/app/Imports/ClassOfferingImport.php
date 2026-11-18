<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class ClassOfferingImport implements WithMultipleSheets
{
    public readonly ClassOfferingSheetImport $classes;

    public readonly ClassOfferingSheetImport $courses;

    public readonly ClassOfferingSheetImport $schedules;

    public function __construct()
    {
        $this->classes = new ClassOfferingSheetImport;
        $this->courses = new ClassOfferingSheetImport;
        $this->schedules = new ClassOfferingSheetImport;
    }

    public function sheets(): array
    {
        return [
            'Kelas' => $this->classes,
            'Mata Kuliah' => $this->courses,
            'Jadwal' => $this->schedules,
        ];
    }
}
