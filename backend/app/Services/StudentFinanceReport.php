<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class StudentFinanceReport
{
    public const GROUPS = [
        'prodi' => 'Berbasis Program Studi',
        'semester' => 'Per Semester',
        'month' => 'Per Bulan',
        'kelas' => 'Berbasis Kelas',
        'student' => 'Per Mahasiswa',
    ];

    /**
     * @param  array{group_by?:string,search?:string,payment_status?:string,month?:string,semester_id?:int|string,study_program_id?:int|string,class_type?:string}  $filters
     */
    public function build(array $filters): array
    {
        $groupBy = array_key_exists($filters['group_by'] ?? '', self::GROUPS) ? $filters['group_by'] : 'prodi';

        $query = DB::table('invoices as i')
            ->join('students as s', 's.id', '=', 'i.student_id')
            ->leftJoin('study_programs as sp', 'sp.id', '=', 's.study_program_id')
            ->leftJoin('semesters as se', 'se.id', '=', 'i.semester_id')
            ->where('i.status', '!=', 'CANCELLED')
            ->when(! empty($filters['search']), function ($q) use ($filters) {
                $term = trim($filters['search']);
                $q->where(function ($sub) use ($term) {
                    $sub->where('s.name', 'like', "%{$term}%")
                        ->orWhere('s.nim', 'like', "%{$term}%");
                });
            })
            ->when(! empty($filters['month']), function ($q) use ($filters) {
                $start = $filters['month'].'-01';
                $q->whereBetween('i.invoice_date', [$start, date('Y-m-t', strtotime($start))]);
            })
            ->when(! empty($filters['semester_id']), fn ($q) => $q->where('i.semester_id', $filters['semester_id']))
            ->when(! empty($filters['study_program_id']), fn ($q) => $q->where('s.study_program_id', $filters['study_program_id']))
            ->when(! empty($filters['class_type']), fn ($q) => $q->where('s.class_type', $filters['class_type']));

        $rows = $query->get([
            'i.student_id', 's.nim', 's.name as student_name', 'i.invoice_date',
            'i.total_amount', 'i.discount_amount', 'i.scholarship_amount', 'i.paid_amount',
            'i.semester_id', 'se.name as semester_name', 'sp.name as prodi_name', 's.class_type',
        ]);

        if ($groupBy === 'student') {
            $groups = $rows->groupBy('student_id');
        } else {
            $groups = $rows->groupBy(function ($r) use ($groupBy) {
                return match ($groupBy) {
                    'month' => $r->invoice_date ? substr($r->invoice_date, 0, 7) : '-',
                    'semester' => $r->semester_name ?? '-',
                    'prodi' => $r->prodi_name ?? '-',
                    default => $r->class_type ?: '-',
                };
            })->sortKeys();
        }

        $paymentStatus = $filters['payment_status'] ?? 'all';
        if ($paymentStatus === 'lunas') {
            $groups = $groups->filter(function ($items) {
                $invoiced = (float) $items->sum('total_amount');
                $discount = (float) $items->sum('discount_amount');
                $scholarship = (float) $items->sum('scholarship_amount');
                $paid = (float) $items->sum('paid_amount');

                return ($invoiced - $discount - $scholarship - $paid) <= 0;
            });
        } elseif ($paymentStatus === 'menunggak') {
            $groups = $groups->filter(function ($items) {
                $invoiced = (float) $items->sum('total_amount');
                $discount = (float) $items->sum('discount_amount');
                $scholarship = (float) $items->sum('scholarship_amount');
                $paid = (float) $items->sum('paid_amount');

                return ($invoiced - $discount - $scholarship - $paid) > 0;
            });
        }

        $data = $groups->map(function ($items, $key) use ($groupBy) {
            $invoiced = (float) $items->sum('total_amount');
            $discount = (float) $items->sum('discount_amount');
            $scholarship = (float) $items->sum('scholarship_amount');
            $paid = (float) $items->sum('paid_amount');
            $outstanding = max(0, $invoiced - $discount - $scholarship - $paid);

            if ($groupBy === 'student') {
                $first = $items->first();
                $status = $outstanding <= 0 ? 'LUNAS' : ($paid > 0 ? 'SEBAGIAN' : 'BELUM_BAYAR');

                return [
                    'student_id' => $first->student_id,
                    'nim' => $first->nim ?? '-',
                    'name' => $first->student_name ?? '-',
                    'prodi' => $first->prodi_name ?? '-',
                    'class_type' => $first->class_type ?: 'REGULER',
                    'label' => ($first->nim ? $first->nim.' - ' : '').$first->student_name,
                    'students' => 1,
                    'invoices' => $items->count(),
                    'invoiced' => $invoiced,
                    'discount' => $discount,
                    'scholarship' => $scholarship,
                    'paid' => $paid,
                    'outstanding' => $outstanding,
                    'status' => $status,
                ];
            }

            return [
                'label' => $groupBy === 'month' ? $this->monthLabel($key) : (string) $key,
                'students' => $items->pluck('student_id')->unique()->count(),
                'invoices' => $items->count(),
                'invoiced' => $invoiced,
                'discount' => $discount,
                'scholarship' => $scholarship,
                'paid' => $paid,
                'outstanding' => $outstanding,
            ];
        });

        if ($groupBy === 'student') {
            $data = $data->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values();
        } else {
            $data = $data->values();
        }

        $dataArray = $data->all();

        $totals = [
            'students' => $groupBy === 'student' ? count($dataArray) : $rows->pluck('student_id')->unique()->count(),
            'invoices' => array_sum(array_column($dataArray, 'invoices')),
            'invoiced' => array_sum(array_column($dataArray, 'invoiced')),
            'discount' => array_sum(array_column($dataArray, 'discount')),
            'scholarship' => array_sum(array_column($dataArray, 'scholarship')),
            'paid' => array_sum(array_column($dataArray, 'paid')),
            'outstanding' => array_sum(array_column($dataArray, 'outstanding')),
        ];

        return [
            'group_by' => $groupBy,
            'group_label' => self::GROUPS[$groupBy],
            'rows' => $dataArray,
            'totals' => $totals,
            'filters' => $this->describeFilters($filters),
        ];
    }

    private function monthLabel(string $ym): string
    {
        $names = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        if (! preg_match('/^(\d{4})-(\d{2})$/', $ym, $m)) {
            return $ym;
        }

        return ($names[(int) $m[2]] ?? $m[2]).' '.$m[1];
    }

    /** @return array<int, string> */
    private function describeFilters(array $f): array
    {
        $out = [];
        if (! empty($f['search'])) {
            $out[] = 'Pencarian: '.$f['search'];
        }
        if (! empty($f['month'])) {
            $out[] = 'Bulan: '.$this->monthLabel($f['month']);
        }
        if (! empty($f['semester_id'])) {
            $out[] = 'Semester: '.(DB::table('semesters')->where('id', $f['semester_id'])->value('name') ?? '-');
        }
        if (! empty($f['study_program_id'])) {
            $out[] = 'Prodi: '.(DB::table('study_programs')->where('id', $f['study_program_id'])->value('name') ?? '-');
        }
        if (! empty($f['class_type'])) {
            $out[] = 'Kelas: '.$f['class_type'];
        }
        if (! empty($f['payment_status']) && $f['payment_status'] !== 'all') {
            $out[] = 'Status: '.($f['payment_status'] === 'lunas' ? 'Lunas' : 'Menunggak');
        }

        return $out;
    }
}
