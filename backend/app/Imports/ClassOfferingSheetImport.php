<?php

namespace App\Imports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class ClassOfferingSheetImport implements ToCollection, WithHeadingRow
{
    private Collection $importedRows;

    public function __construct()
    {
        $this->importedRows = collect();
    }

    public function collection(Collection $rows): void
    {
        $this->importedRows = $rows;
    }

    public function rows(): Collection
    {
        return $this->importedRows;
    }
}
