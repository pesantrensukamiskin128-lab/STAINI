<?php

namespace App\Exports;

use App\Models\Curriculum;
use App\Models\GraduateProfile;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;

class CurriculumObeWorkbookExport implements WithMultipleSheets
{
    public function __construct(
        private readonly ?Curriculum $curriculum = null,
        private readonly bool $template = false,
    ) {}

    public function sheets(): array
    {
        $curriculum = $this->curriculum;
        $profiles = $curriculum
            ? GraduateProfile::query()->where('curriculum_id', $curriculum->id)->orderBy('order')->orderBy('code')->get()
            : collect();
        $outcomes = $curriculum?->learningOutcomes()->with('graduateProfiles')->orderBy('order')->orderBy('code')->get() ?? collect();
        $courses = $curriculum?->courses()->orderBy('curriculum_courses.semester')->orderBy('courses.code')->get() ?? collect();

        $mappings = [];
        if ($curriculum) {
            $curriculumCourseIds = $courses->pluck('id');
            foreach ($outcomes as $outcome) {
                $mappedCourses = DB::table('cpl_course_mappings')
                    ->join('courses', 'courses.id', '=', 'cpl_course_mappings.course_id')
                    ->where('cpl_course_mappings.learning_outcome_id', $outcome->id)
                    ->whereIn('cpl_course_mappings.course_id', $curriculumCourseIds)
                    ->orderBy('courses.code')
                    ->get(['courses.code', 'cpl_course_mappings.support_level']);

                foreach ($mappedCourses as $mapping) {
                    $mappings[] = [$outcome->code, $mapping->code, 'Ya', $mapping->support_level];
                }
            }
        }

        return [
            new CurriculumObeSheet('PETUNJUK', [
                ['Template detail kurikulum OBE'],
                ['Import bersifat tambah/perbarui (merge) berdasarkan kode; baris yang tidak disertakan tidak menghapus data.'],
                ['PROFIL_LULUSAN: isi kode, nama, deskripsi, dan urutan. Kode profil harus unik dalam kurikulum.'],
                ['CPL: isi kode, kategori, deskripsi, urutan, dan kode profil terkait dipisahkan tanda |. Kategori: Sikap, Pengetahuan, Keterampilan Umum, Keterampilan Khusus. Pada baris CPL yang diimpor, graduate_profile_codes kosong akan menghapus tautan profil terkait.'],
                ['MATA_KULIAH: kode harus sudah tersedia pada master mata kuliah program studi kurikulum. Semester 1-8; wajib diisi Ya atau Tidak.'],
                ['PEMETAAN_CPL_MK: masukkan kode CPL dan kode mata kuliah yang sudah ada di kurikulum. mapped=Ya untuk menambah/memperbarui; mapped=Tidak untuk menghapus pasangan tersebut. Level: Tinggi, Sedang, Rendah.'],
                ['Sheet yang tidak digunakan boleh dibiarkan kosong. Baris kosong diabaikan. Jangan mengubah nama sheet atau header kolom.'],
            ]),
            new CurriculumObeSheet('PROFIL_LULUSAN', $this->template
                ? [['code', 'name', 'description', 'order']]
                : array_merge(
                    [['code', 'name', 'description', 'order']],
                    $profiles->map(fn ($profile) => [$profile->code, $profile->name, $profile->description, $profile->order])->all()
                )),
            new CurriculumObeSheet('CPL', $this->template
                ? [['code', 'category', 'description', 'graduate_profile_codes', 'order']]
                : array_merge(
                    [['code', 'category', 'description', 'graduate_profile_codes', 'order']],
                    $outcomes->map(fn ($outcome) => [
                        $outcome->code,
                        $outcome->category,
                        $outcome->description,
                        $outcome->graduateProfiles->pluck('code')->implode('|'),
                        $outcome->order,
                    ])->all()
                )),
            new CurriculumObeSheet('MATA_KULIAH', $this->template
                ? [['course_code', 'semester', 'required']]
                : array_merge(
                    [['course_code', 'semester', 'required']],
                    $courses->map(fn ($course) => [
                        $course->code,
                        $course->pivot->semester,
                        $course->pivot->is_required ? 'Ya' : 'Tidak',
                    ])->all()
                )),
            new CurriculumObeSheet('PEMETAAN_CPL_MK', $this->template
                ? [['cpl_code', 'course_code', 'mapped', 'level']]
                : array_merge([['cpl_code', 'course_code', 'mapped', 'level']], $mappings)),
        ];
    }
}

class CurriculumObeSheet implements FromArray, ShouldAutoSize, WithTitle
{
    public function __construct(
        private readonly string $title,
        private readonly array $rows,
    ) {}

    public function array(): array
    {
        return $this->rows;
    }

    public function title(): string
    {
        return $this->title;
    }
}
