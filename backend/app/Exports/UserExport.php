<?php

namespace App\Exports;

use App\Models\User;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class UserExport implements FromQuery, WithHeadings, WithMapping, WithStyles, ShouldAutoSize, WithTitle
{
    public function __construct(
        private readonly ?string $search = null,
        private readonly ?string $role = null,
    ) {}

    public function title(): string
    {
        return 'Pengguna';
    }

    public function query()
    {
        return User::with('roles')
            ->when($this->search, fn ($query) => $query->where(function ($nested) {
                $nested->where('name', 'like', "%{$this->search}%")
                    ->orWhere('email', 'like', "%{$this->search}%")
                    ->orWhere('username', 'like', "%{$this->search}%");
            }))
            ->when($this->role, fn ($query) => $query->role($this->role))
            ->orderBy('name');
    }

    public function headings(): array
    {
        return ['Nama', 'Email', 'Username', 'Role', 'Status', 'Login Terakhir'];
    }

    public function map($user): array
    {
        return [
            $user->name,
            $user->email,
            $user->username ?? '',
            $user->roles->pluck('name')->implode(', '),
            $user->is_active ? 'Aktif' : 'Nonaktif',
            $user->last_login_at?->format('Y-m-d H:i:s') ?? '',
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2563EB']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
            ],
        ];
    }
}
