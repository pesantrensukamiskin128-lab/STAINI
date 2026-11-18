<?php

namespace App\Imports;

use App\Models\Course;
use App\Models\Curriculum;
use App\Models\GraduateProfile;
use App\Models\LearningOutcome;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CurriculumObeWorkbookImport
{
    private array $errors = [];

    public function __construct(
        private readonly Curriculum $curriculum,
        private readonly array $sheets,
    ) {}

    public function import(): array
    {
        $profiles = $this->readSheet(1, ['code', 'name', 'description', 'order']);
        $outcomes = $this->readSheet(2, ['code', 'category', 'description', 'graduate_profile_codes', 'order']);
        $courses = $this->readSheet(3, ['course_code', 'semester', 'required']);
        $mappings = $this->readSheet(4, ['cpl_code', 'course_code', 'mapped', 'level']);

        if (! $profiles && ! $outcomes && ! $courses && ! $mappings) {
            $this->errors[] = 'Tidak ada data untuk diimpor.';
        }

        $this->validateDuplicates($profiles, 'code', 'PROFIL_LULUSAN');
        $this->validateDuplicates($outcomes, 'code', 'CPL');
        $this->validateDuplicates($courses, 'course_code', 'MATA_KULIAH');
        $this->validateMappingDuplicates($mappings);

        $existingProfiles = GraduateProfile::query()
            ->where('curriculum_id', $this->curriculum->id)
            ->get()
            ->keyBy(fn (GraduateProfile $profile) => $this->key($profile->code));
        $existingOutcomes = LearningOutcome::query()
            ->where('curriculum_id', $this->curriculum->id)
            ->get()
            ->keyBy(fn (LearningOutcome $outcome) => $this->key($outcome->code));

        $profileCodes = $existingProfiles->keys()->merge(collect($profiles)->pluck('code')->map(fn ($code) => $this->key($code)))->unique();
        $outcomeCodes = $existingOutcomes->keys()->merge(collect($outcomes)->pluck('code')->map(fn ($code) => $this->key($code)))->unique();

        foreach ($profiles as $row) {
            $this->required($row, 'code', 'PROFIL_LULUSAN');
            $this->required($row, 'name', 'PROFIL_LULUSAN');
            if (mb_strlen($row['code']) > 20 || mb_strlen($row['name']) > 255) {
                $this->errors[] = "PROFIL_LULUSAN baris {$row['_row']}: kode maksimal 20 karakter dan nama maksimal 255 karakter.";
            }
            $this->optionalOrder($row, 'PROFIL_LULUSAN');
        }

        $allowedCategories = ['Sikap', 'Pengetahuan', 'Keterampilan Umum', 'Keterampilan Khusus'];
        foreach ($outcomes as $row) {
            $this->required($row, 'code', 'CPL');
            $this->required($row, 'description', 'CPL');
            if (mb_strlen($row['code']) > 20) {
                $this->errors[] = "CPL baris {$row['_row']}: kode maksimal 20 karakter.";
            }
            if ($row['category'] !== '' && ! in_array($row['category'], $allowedCategories, true)) {
                $this->errors[] = "CPL baris {$row['_row']}: kategori tidak dikenal.";
            }
            $this->optionalOrder($row, 'CPL');
            foreach ($this->codes($row['graduate_profile_codes']) as $code) {
                if (! $profileCodes->contains($this->key($code))) {
                    $this->errors[] = "CPL baris {$row['_row']}: kode profil lulusan \"{$code}\" tidak ditemukan pada kurikulum ini.";
                }
            }
        }

        $courseCodes = collect($courses)->pluck('course_code')->map(fn ($code) => $this->key($code))->unique();
        $curriculumCourseCodes = $this->curriculum->courses()
            ->pluck('courses.code')
            ->map(fn ($code) => $this->key($code));
        $availableCourseCodes = $courseCodes->merge($curriculumCourseCodes)->unique();
        $courseLookup = Course::query()
            ->where('study_program_id', $this->curriculum->study_program_id)
            ->get()
            ->keyBy(fn (Course $course) => $this->key($course->code));

        foreach ($courses as $row) {
            $this->required($row, 'course_code', 'MATA_KULIAH');
            if (! $row['semester'] || ! ctype_digit($row['semester']) || (int) $row['semester'] < 1 || (int) $row['semester'] > 8) {
                $this->errors[] = "MATA_KULIAH baris {$row['_row']}: semester harus berupa angka 1 sampai 8.";
            }
            if (! in_array($this->key($row['required']), ['YA', 'TIDAK', '1', '0'], true)) {
                $this->errors[] = "MATA_KULIAH baris {$row['_row']}: required harus diisi Ya atau Tidak.";
            }
            if (! $courseLookup->has($this->key($row['course_code']))) {
                $this->errors[] = "MATA_KULIAH baris {$row['_row']}: kode mata kuliah \"{$row['course_code']}\" tidak ditemukan pada master program studi kurikulum.";
            }
        }

        foreach ($mappings as $row) {
            $this->required($row, 'cpl_code', 'PEMETAAN_CPL_MK');
            $this->required($row, 'course_code', 'PEMETAAN_CPL_MK');
            if (! $outcomeCodes->contains($this->key($row['cpl_code']))) {
                $this->errors[] = "PEMETAAN_CPL_MK baris {$row['_row']}: kode CPL \"{$row['cpl_code']}\" tidak ditemukan pada kurikulum ini.";
            }
            if (! $availableCourseCodes->contains($this->key($row['course_code']))) {
                $this->errors[] = "PEMETAAN_CPL_MK baris {$row['_row']}: kode mata kuliah \"{$row['course_code']}\" belum terdaftar pada kurikulum atau sheet MATA_KULIAH.";
            }
            if (! in_array($this->key($row['mapped']), ['YA', 'TIDAK', '1', '0'], true)) {
                $this->errors[] = "PEMETAAN_CPL_MK baris {$row['_row']}: mapped harus diisi Ya atau Tidak.";
            }
            if ($row['level'] !== '' && ! in_array($row['level'], ['Tinggi', 'Sedang', 'Rendah'], true)) {
                $this->errors[] = "PEMETAAN_CPL_MK baris {$row['_row']}: level harus Tinggi, Sedang, atau Rendah.";
            }
        }

        if ($this->errors) {
            throw ValidationException::withMessages(['file' => array_slice($this->errors, 0, 50)]);
        }

        DB::transaction(function () use ($profiles, $outcomes, $courses, $mappings, $courseLookup): void {
            $profileIds = GraduateProfile::query()
                ->where('curriculum_id', $this->curriculum->id)
                ->get()
                ->keyBy(fn (GraduateProfile $profile) => $this->key($profile->code));

            foreach ($profiles as $row) {
                $profile = $profileIds->get($this->key($row['code'])) ?? new GraduateProfile([
                    'curriculum_id' => $this->curriculum->id,
                    'code' => $row['code'],
                ]);
                $profile->name = $row['name'];
                $profile->code = $row['code'];
                $profile->description = $row['description'] !== '' ? $row['description'] : null;
                $profile->order = $row['order'] !== '' ? (int) $row['order'] : ($profile->exists ? $profile->order : $profileIds->count() + 1);
                $profile->save();
                $profileIds->put($this->key($profile->code), $profile);
            }

            $outcomeIds = LearningOutcome::query()
                ->where('curriculum_id', $this->curriculum->id)
                ->get()
                ->keyBy(fn (LearningOutcome $outcome) => $this->key($outcome->code));

            foreach ($outcomes as $row) {
                $outcome = $outcomeIds->get($this->key($row['code'])) ?? new LearningOutcome([
                    'curriculum_id' => $this->curriculum->id,
                    'code' => $row['code'],
                ]);
                $outcome->category = $row['category'] !== '' ? $row['category'] : null;
                $outcome->code = $row['code'];
                $outcome->description = $row['description'];
                $outcome->order = $row['order'] !== '' ? (int) $row['order'] : ($outcome->exists ? $outcome->order : $outcomeIds->count() + 1);
                $outcome->save();

                $profileIdsForOutcome = collect($this->codes($row['graduate_profile_codes']))
                    ->map(fn ($code) => $profileIds->get($this->key($code))?->id)
                    ->filter()
                    ->all();
                $outcome->graduateProfiles()->sync($profileIdsForOutcome);
                $outcomeIds->put($this->key($outcome->code), $outcome);
            }

            foreach ($courses as $row) {
                $course = $courseLookup->get($this->key($row['course_code']));
                $this->curriculum->courses()->syncWithoutDetaching([
                    $course->id => [
                        'semester' => (int) $row['semester'],
                        'is_required' => in_array($this->key($row['required']), ['YA', '1'], true),
                    ],
                ]);
            }

            foreach ($mappings as $row) {
                $outcome = $outcomeIds->get($this->key($row['cpl_code']));
                $course = $courseLookup->get($this->key($row['course_code']));
                $mapping = DB::table('cpl_course_mappings')
                    ->where('learning_outcome_id', $outcome->id)
                    ->where('course_id', $course->id);

                if (in_array($this->key($row['mapped']), ['YA', '1'], true)) {
                    $attributes = [
                        'support_level' => $row['level'] !== '' ? $row['level'] : 'Sedang',
                        'updated_at' => now(),
                    ];
                    if ($mapping->exists()) {
                        $mapping->update($attributes);
                    } else {
                        DB::table('cpl_course_mappings')->insert([
                            'learning_outcome_id' => $outcome->id,
                            'course_id' => $course->id,
                            ...$attributes,
                            'created_at' => now(),
                        ]);
                    }
                } else {
                    $mapping->delete();
                }
            }
        });

        return [
            'profiles' => count($profiles),
            'learning_outcomes' => count($outcomes),
            'courses' => count($courses),
            'mappings' => count($mappings),
        ];
    }

    private function readSheet(int $index, array $expectedHeaders): array
    {
        $rows = $this->sheets[$index] ?? [];
        if (! $rows) {
            $this->errors[] = 'Workbook tidak memiliki semua sheet template kurikulum.';

            return [];
        }

        $headers = array_map(fn ($header) => strtolower(trim((string) $header)), $rows[0] ?? []);
        if (array_slice($headers, 0, count($expectedHeaders)) !== $expectedHeaders) {
            $this->errors[] = 'Header sheet '.$this->sheetName($index).' tidak sesuai template.';

            return [];
        }

        $result = [];
        foreach (array_slice($rows, 1, null, true) as $offset => $values) {
            $values = array_pad($values, count($expectedHeaders), null);
            $row = ['_row' => $offset + 1];
            foreach ($expectedHeaders as $column => $header) {
                $value = $values[$column] ?? '';
                $row[$header] = is_scalar($value) ? trim((string) $value) : '';
            }
            if (collect($row)->except('_row')->every(fn ($value) => $value === '')) {
                continue;
            }
            $result[] = $row;
        }

        return $result;
    }

    private function sheetName(int $index): string
    {
        return [1 => 'PROFIL_LULUSAN', 2 => 'CPL', 3 => 'MATA_KULIAH', 4 => 'PEMETAAN_CPL_MK'][$index] ?? 'kurikulum';
    }

    private function validateDuplicates(array $rows, string $field, string $sheet): void
    {
        $seen = [];
        foreach ($rows as $row) {
            $key = $this->key($row[$field]);
            if ($key !== '' && isset($seen[$key])) {
                $this->errors[] = "{$sheet} baris {$row['_row']}: kode \"{$row[$field]}\" duplikat.";
            }
            $seen[$key] = true;
        }
    }

    private function validateMappingDuplicates(array $rows): void
    {
        $seen = [];
        foreach ($rows as $row) {
            $key = $this->key($row['cpl_code']).'|'.$this->key($row['course_code']);
            if ($key !== '|' && isset($seen[$key])) {
                $this->errors[] = "PEMETAAN_CPL_MK baris {$row['_row']}: pasangan CPL dan mata kuliah duplikat.";
            }
            $seen[$key] = true;
        }
    }

    private function required(array $row, string $field, string $sheet): void
    {
        if ($row[$field] === '') {
            $this->errors[] = "{$sheet} baris {$row['_row']}: kolom {$field} wajib diisi.";
        }
    }

    private function optionalOrder(array $row, string $sheet): void
    {
        if ($row['order'] !== '' && (! ctype_digit($row['order']) || (int) $row['order'] < 0)) {
            $this->errors[] = "{$sheet} baris {$row['_row']}: order harus berupa bilangan bulat nol atau lebih.";
        }
    }

    private function codes(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode('|', $value)), fn ($code) => $code !== ''));
    }

    private function key(string $value): string
    {
        return mb_strtoupper(trim($value));
    }
}
