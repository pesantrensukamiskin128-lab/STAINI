<?php

namespace App\Exports;

use App\Models\Rpkps;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\FromArray;

class RpkpsWorkbookExport implements WithMultipleSheets
{
    public function __construct(private ?Rpkps $rpkps = null, private bool $template = false) {}

    public function sheets(): array
    {
        $names = ['PETUNJUK', 'IDENTITAS', 'CPMK', 'SUB_CPMK', 'BAHAN_KAJIAN', 'RENCANA_MINGGUAN', 'ASESMEN', 'INSTRUMEN', 'RUBRIK', 'SKALA_NILAI', 'REFERENSI'];
        return array_map(fn ($name) => new RpkpsSheet($name, $this->rows($name)), $names);
    }

    private function rows(string $name): array
    {
        if ($this->template) {
            return match ($name) {
                'PETUNJUK' => [['Template Import RPKPS/RPS'], ['Nama kolom pada setiap sheet harus dipertahankan karena dipetakan ke tabel-tabel RPS. CPL diambil otomatis dari kurikulum; hubungkan CPMK ke CPL lewat cpl_codes menggunakan kode CPL yang valid dan pemisah |, contoh: CPL-01|CPL-02.'], ['Satu CPMK dapat memiliki beberapa Sub-CPMK. Kode Sub-CPMK dibuat otomatis dengan pola Sub-n.m sesuai urutan CPMK dan urutan baris pada sheet SUB_CPMK; kolom code tetap disertakan sebagai referensi.'], ['Isi sub_cpmk pada rencana mingguan dengan satu atau beberapa kode dari sheet SUB_CPMK, pisahkan beberapa kode dengan |. Isi methods dengan daftar metode yang dipisahkan |, contoh: Ceramah|Diskusi. Minggu 8 dan 16 otomatis menjadi evaluasi tengah dan akhir semester.'], ['Pada sheet REFERENSI, type hanya boleh Utama atau Pendukung; category adalah teks bebas maksimal 100 karakter. Pada sheet ASESMEN, total weight harus 100. Nilai min_score dan max_score berada pada rentang 0–100.'], ['INSTRUMEN dan RUBRIK ditautkan ke ASESMEN melalui assessment_name yang harus sama persis; sub_cpmk memakai kode dari sheet SUB_CPMK.'], ['Import memperbarui data dengan kunci kode/urutan dan mempertahankan baris RPS yang tidak disertakan. Setiap sheet sudah berisi contoh untuk seluruh kolom; hapus baris contoh sebelum import.']],
                'IDENTITAS' => [['course_code','curriculum_code','academic_year','semester_name','lecturer_nidn','coordinator_nidn','course_description','course_urgency','course_scope','course_position','prerequisites'], ['MK-CONTOH','KUR-2025','2025/2026','Ganjil','1234567890','0987654321','Mata kuliah contoh untuk panduan pengisian RPS.','Penting untuk membangun kompetensi bidang studi.','Membahas konsep dan praktik dasar sesuai capaian pembelajaran.','Mata kuliah wajib pada program studi.','Tidak ada prasyarat']],
                'CPMK' => [['code','description','cpl_codes','order'], ['CPMK-01','Contoh capaian pembelajaran mata kuliah','CPL-01',1]],
                'SUB_CPMK' => [['cpmk_code','code','description','order'], ['CPMK-01','Sub-1.1','Contoh sub-CPMK',1]],
                'BAHAN_KAJIAN' => [['topic','subtopics','order'], ['Contoh bahan kajian','Subtopik',1]],
                'RENCANA_MINGGUAN' => [['week_number','sub_cpmk','indicators','learning_material','methods','offline_learning','online_learning','lecturer_activity','student_activity','assessment_form','assessment_criteria','media','duration','weight'], [1,'Sub-1.1','Mahasiswa mampu menjelaskan konsep dasar.','Konsep dasar dan studi kasus.','Ceramah|Diskusi','2 x 50 menit tatap muka','1 x 60 menit pembelajaran daring.','Menjelaskan materi dan memandu diskusi.','Membaca materi dan mengerjakan latihan.','Tes tertulis','Ketepatan menjelaskan konsep.','LCD dan LMS','2x50 menit',5]],
                'ASESMEN' => [['name','weight','description','order','final_grade_formula'], ['Tugas',20,'Tugas individu untuk mengukur pemahaman materi.',1,'Nilai akhir = Tugas 20% + UTS 30% + UAS 50%'], ['UTS',30,'Ujian tengah semester.',2,''], ['UAS',50,'Ujian akhir semester.',3,'']],
                'INSTRUMEN' => [['assessment_name','sub_cpmk','instrument_name','instrument_type','technique','cognitive_level','affective_level','psychomotor_level','item_numbers','weight','timing','description','order'], ['Tugas','Sub-1.1','Instrumen penilaian tugas','Non-tes','Observasi','C2 - Memahami','A2 - Merespons','P2 - Menirukan','1-5',20,'Minggu 1','Lembar observasi untuk menilai tugas mahasiswa.',1]],
                'RUBRIK' => [['assessment_name','title','criteria','weight','excellent','good','fair','poor','very_poor','order'], ['Tugas','Rubrik tugas','Kriteria',100,'Sangat baik','Baik','Cukup','Kurang','Sangat kurang',1]],
                'SKALA_NILAI' => [['min_score','max_score','letter','grade_point','order'], [85,100,'A',4,1]],
                'REFERENSI' => [['type','category','title','author','year','publisher','isbn_doi','url','order'], ['Utama','Buku','Pengantar Pembelajaran','Penulis Contoh','2026','Penerbit Contoh','978-000-000-000-0','https://contoh.ac.id/referensi',1]],
            };
        }

        $r = $this->rpkps;
        return match ($name) {
            'PETUNJUK' => [['Export RPKPS/RPS', $r?->code ?? '']],
            'IDENTITAS' => [['course_id','curriculum_id','academic_year_id','semester_id','lecturer_id','coordinator_id','course_description','course_urgency','course_scope','course_position','prerequisites'], [$r->course_id,$r->curriculum_id,$r->academic_year_id,$r->semester_id,$r->lecturer_id,$r->coordinator_id,$r->course_description,$r->course_urgency,$r->course_scope,$r->course_position,$r->prerequisites]],
            'CPMK' => array_merge(
                [['code','description','cpl_codes','order']],
                $r->cpmks->map(fn ($x) => [
                    $x->code,
                    $x->description,
                    $x->cpls->pluck('code')->implode('|'),
                    $x->order,
                ])->all()
            ),
            'SUB_CPMK' => array_merge([['cpmk_code','code','description','order']], $r->cpmks->flatMap(fn ($c) => $c->subCpmks->map(fn ($x) => [$c->code,$x->code,$x->description,$x->order]))->all()),
            'BAHAN_KAJIAN' => array_merge([['topic','subtopics','order']], $r->learningMaterials->map(fn ($x) => [$x->topic,$x->subtopics,$x->order])->all()),
            'RENCANA_MINGGUAN' => array_merge([['week_number','sub_cpmk','indicators','learning_material','methods','offline_learning','online_learning','lecturer_activity','student_activity','assessment_form','assessment_criteria','media','duration','weight']], $r->weeklyPlans->map(fn ($x) => [$x->week_number,in_array((int) $x->week_number,[8,16],true)?((int) $x->week_number===8?'Evaluasi Tengah Semester':'Evaluasi Akhir Semester'):$x->sub_cpmk,$x->indicators,$x->learning_material,is_array($x->methods)?implode('|',$x->methods):$x->methods,$x->offline_learning,$x->online_learning,$x->lecturer_activity,$x->student_activity,$x->assessment_form,$x->assessment_criteria,$x->media,$x->duration,$x->weight])->all()),
            'ASESMEN' => array_merge([['name','weight','description','order','final_grade_formula']], $r->assessments->map(fn ($x) => [$x->name,$x->weight,$x->description,$x->order,$r->final_grade_formula])->all()),
            'INSTRUMEN' => array_merge([['assessment_name','sub_cpmk','instrument_name','instrument_type','technique','cognitive_level','affective_level','psychomotor_level','item_numbers','weight','timing','description','order']], $r->assessmentInstruments->map(fn ($x) => [$x->assessment?->name,$x->sub_cpmk,$x->instrument_name,$x->instrument_type,$x->technique,$x->cognitive_level,$x->affective_level,$x->psychomotor_level,$x->item_numbers,$x->weight,$x->timing,$x->description,$x->order])->all()),
            'RUBRIK' => array_merge([['assessment_name','title','criteria','weight','excellent','good','fair','poor','very_poor','order']], $r->rubrics->map(fn ($x) => [$x->assessment?->name,$x->title,$x->criteria,$x->weight,$x->excellent,$x->good,$x->fair,$x->poor,$x->very_poor,$x->order])->all()),
            'SKALA_NILAI' => array_merge([['min_score','max_score','letter','grade_point','order']], $r->gradeScales->map(fn ($x) => [$x->min_score,$x->max_score,$x->letter,$x->grade_point,$x->order])->all()),
            'REFERENSI' => array_merge([['type','category','title','author','year','publisher','isbn_doi','url','order']], $r->references->map(fn ($x) => [$x->type,$x->category,$x->title,$x->author,$x->year,$x->publisher,$x->isbn_doi,$x->url,$x->order])->all()),
        };
    }
}

class RpkpsSheet implements FromArray, WithTitle
{
    public function __construct(private string $title, private array $rows) {}
    public function array(): array { return $this->rows; }
    public function title(): string { return $this->title; }
}
