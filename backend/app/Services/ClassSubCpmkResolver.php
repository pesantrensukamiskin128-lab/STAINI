<?php

namespace App\Services;

use App\Models\ClassModel;
use App\Models\Rpkps;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ClassSubCpmkResolver
{
    public function forClass(ClassModel $class): Collection
    {
        $query = Rpkps::query()
            ->with([
                'cpmks:id,rpkps_id,code,description,order',
                'cpmks.subCpmks:id,rpkps_cpmk_id,code,description,order',
            ])
            ->where('course_id', $class->course_id)
            ->whereIn('status', ['DISETUJUI', 'DIKUNCI', 'DIARSIPKAN'])
            ->where(function ($query) use ($class): void {
                if ($class->semester_id) {
                    $query->whereNull('semester_id')->orWhere('semester_id', $class->semester_id);
                } else {
                    $query->whereNull('semester_id');
                }
            });

        if ($class->semester_id) {
            $query->orderByRaw('CASE WHEN semester_id = ? THEN 0 ELSE 1 END', [$class->semester_id]);
        }

        $rpkps = $query->orderByDesc('updated_at')->first();
        if (!$rpkps) {
            return collect();
        }

        return $rpkps->cpmks->flatMap(fn ($cpmk) => $cpmk->subCpmks->map(
            fn ($subCpmk): array => [
                'id' => (int) $subCpmk->id,
                'code' => $subCpmk->code,
                'description' => $subCpmk->description,
                'cpmk' => [
                    'id' => (int) $cpmk->id,
                    'code' => $cpmk->code,
                    'description' => $cpmk->description,
                ],
            ]
        ))->values();
    }

    /**
     * @param array<int, int|string> $ids
     */
    public function assertBelongsToClass(ClassModel $class, array $ids): void
    {
        $requestedIds = collect($ids)->map(fn ($id): int => (int) $id)->unique();
        $allowedIds = $this->forClass($class)->pluck('id');

        if ($requestedIds->diff($allowedIds)->isNotEmpty()) {
            throw ValidationException::withMessages([
                'sub_cpmk_ids' => ['Sub-CPMK harus berasal dari RPKPS yang berlaku untuk mata kuliah dan semester kelas ini.'],
            ]);
        }
    }
}
