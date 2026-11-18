<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AcademicCalendar;
use App\Models\AcademicYear;
use App\Models\ClassModel;
use App\Models\Event;
use App\Models\HonorPayroll;
use App\Models\Lecturer;
use App\Models\LecturerPosition;
use App\Models\Krs;
use App\Models\OutgoingLetter;
use App\Models\MeetingMinutes;
use App\Models\PmbRegistrant;
use App\Models\Rpkps;
use App\Models\Semester;
use App\Models\Student;
use App\Models\StudentGrade;
use App\Models\Staff;
use App\Models\User;
use App\Support\AcademicDocumentVerification;
use App\Support\OperationalDocumentVerification;
use App\Support\HonorSlipVerification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class VerifyController extends Controller
{
    public function verifyHonorSlip(Request $request, string $token): JsonResponse
    {
        $recipient = $request->query('recipient', '');
        if (! is_string($recipient)) {
            return response()->json(['valid' => false, 'message' => 'Identitas slip honor tidak valid.'], 404);
        }
        if (! preg_match('/^(lecturer|staff):([1-9][0-9]*)$/', $recipient, $matches)) {
            return response()->json(['valid' => false, 'message' => 'Identitas slip honor tidak valid.'], 404);
        }

        $recipientType = $matches[1];
        $recipientId = (int) $matches[2];
        $financeSignerId = filter_var($request->query('finance_signer_id', 0), FILTER_VALIDATE_INT);
        $waket2SignerId = filter_var($request->query('waket2_signer_id', 0), FILTER_VALIDATE_INT);
        if ($financeSignerId === false || $financeSignerId < 0 || $waket2SignerId === false || $waket2SignerId < 0) {
            return response()->json(['valid' => false, 'message' => 'Identitas penandatangan tidak valid.'], 404);
        }
        $financeSignerName = $request->query('finance_signer_name', '');
        $waket2SignerName = $request->query('waket2_signer_name', '');
        if (! is_string($financeSignerName) || ! is_string($waket2SignerName)) {
            return response()->json(['valid' => false, 'message' => 'Identitas penandatangan tidak valid.'], 404);
        }
        $recipientRecord = $recipientType === 'lecturer'
            ? Lecturer::query()->find($recipientId)
            : Staff::query()->find($recipientId);
        $payrollId = OperationalDocumentVerification::id($token);
        $payroll = $payrollId ? HonorPayroll::query()->find($payrollId) : null;
        if (! $payroll || $payroll->status !== 'PAID' || ! $recipientRecord) {
            return response()->json(['valid' => false, 'message' => 'Slip honor tidak ditemukan atau belum dibayar.'], 404);
        }
        $recipientName = $recipientRecord instanceof Lecturer ? $recipientRecord->display_name : $recipientRecord->name;
        $recipientIdentifier = $recipientRecord instanceof Lecturer ? $recipientRecord->nidn : $recipientRecord->nip;

        $items = $payroll->items()
            ->where($recipientType === 'lecturer' ? 'lecturer_id' : 'staff_id', $recipientId)
            ->orderBy('activity_date')
            ->orderBy('id')
            ->get();
        $deductions = $payroll->deductions()
            ->where($recipientType === 'lecturer' ? 'lecturer_id' : 'staff_id', $recipientId)
            ->orderBy('id')
            ->get();
        $reference = HonorSlipVerification::reference(
            $payroll,
            $recipientType,
            $recipientId,
            $recipientName,
            $recipientIdentifier,
            $items,
            $financeSignerId,
            $financeSignerName === '' ? null : $financeSignerName,
            $waket2SignerId,
            $waket2SignerName === '' ? null : $waket2SignerName,
            $deductions,
        );
        if ($items->isEmpty() || ! OperationalDocumentVerification::matches($token, 'honor-slip', $payroll->id, $reference)) {
            return response()->json([
                'valid' => false,
                'message' => 'Data slip honor telah berubah atau token verifikasi tidak valid.',
            ], 404);
        }

        $signer = $request->query('signer', '');
        if (! is_string($signer)) {
            return response()->json(['valid' => false, 'message' => 'Jenis tanda tangan tidak valid.'], 404);
        }
        $financeSignerSigned = $financeSignerId > 0 && $financeSignerName !== '';
        $waket2SignerSigned = $waket2SignerId > 0 && $waket2SignerName !== '';
        $signerInfo = match ($signer) {
            'finance' => [
                'label' => 'Bagian Keuangan',
                'name' => $financeSignerName ?: null,
                'signed' => $financeSignerSigned,
                'signed_at' => $payroll->paid_at?->translatedFormat('d F Y, H:i'),
            ],
            'waket2' => [
                'label' => 'Wakil Ketua II',
                'name' => $waket2SignerName ?: null,
                'signed' => $waket2SignerSigned,
                'signed_at' => $waket2SignerSigned ? $payroll->paid_at?->translatedFormat('d F Y, H:i') : null,
            ],
            default => null,
        };

        return response()->json([
            'valid' => true,
            'document' => 'SLIP HONOR',
            'document_type' => 'honor-slip',
            'number' => HonorSlipVerification::number($payroll, $recipientType, $recipientId),
            'period' => $payroll->period_type === 'MONTHLY'
                ? $payroll->period_start->locale('id')->translatedFormat('F Y')
                : ($payroll->period_type === 'INCIDENTAL'
                    ? 'Insidental · '.$payroll->period_start->locale('id')->translatedFormat('d F Y')
                    : ($payroll->semester?->name ?? $payroll->period_key)),
            'paid_at' => $payroll->paid_at?->translatedFormat('d F Y'),
            'items_count' => $items->count(),
            'status' => 'Telah dibayar',
            'is_valid' => true,
            'signer_info' => $signerInfo,
        ]);
    }

    public function verifyKhs(Request $request, string $token): JsonResponse
    {
        return $this->verifyAcademicDocument($request, $token, 'khs');
    }

    public function verifyTranscript(Request $request, string $token): JsonResponse
    {
        return $this->verifyAcademicDocument($request, $token, 'transcript');
    }

    public function verifyEventAttendance(Request $request, string $token): JsonResponse
    {
        $id = OperationalDocumentVerification::id($token);
        $event = $id ? Event::with('creator')->withCount('attendances')->find($id) : null;
        if (! $event || ! OperationalDocumentVerification::matches($token, 'event-attendance', $event->id, (string) $event->qr_token)) {
            return response()->json(['valid' => false, 'message' => 'Token verifikasi daftar hadir tidak valid.'], 404);
        }

        $signerName = $event->organizer ?: $event->creator?->name;

        return response()->json([
            'valid' => true,
            'document' => 'DAFTAR HADIR AGENDA',
            'document_type' => 'event-attendance',
            'event' => [
                'title' => $event->title,
                'date' => $event->event_date?->translatedFormat('d F Y'),
                'location' => $event->location,
                'organizer' => $event->organizer,
                'attendances_count' => $event->attendances_count,
            ],
            'signed_by' => $signerName,
            'signer_position' => 'Penanggung Jawab/Penyelenggara',
            'is_valid' => true,
            'signer_info' => $request->query('signer') === 'organizer' ? [
                'label' => 'Penanggung Jawab/Penyelenggara',
                'name' => $signerName,
                'signed' => filled($signerName),
            ] : null,
        ]);
    }

    public function verifyPmbCard(Request $request, string $token): JsonResponse
    {
        $id = OperationalDocumentVerification::id($token);
        $registrant = $id ? PmbRegistrant::with(['period', 'path', 'studyProgramChoice1', 'verifiedBy'])->find($id) : null;
        if (! $registrant || ! OperationalDocumentVerification::matches($token, 'pmb-card', $registrant->id, (string) $registrant->registration_number)) {
            return response()->json(['valid' => false, 'message' => 'Token verifikasi kartu peserta tidak valid.'], 404);
        }

        $signerName = $registrant->verifiedBy?->name ?? 'Panitia PMB';

        return response()->json([
            'valid' => true,
            'document' => 'KARTU PESERTA PMB',
            'document_type' => 'pmb-card',
            'registrant' => [
                'registration_number' => $registrant->registration_number,
                'name' => $registrant->full_name,
                'period' => $registrant->period?->name,
                'path' => $registrant->path?->name,
                'study_program' => $registrant->studyProgramChoice1?->name,
                'status' => $registrant->status,
            ],
            'signed_by' => $signerName,
            'signer_position' => 'Verifikator/Panitia PMB',
            'issued_at' => $registrant->verified_at?->translatedFormat('d F Y, H:i'),
            'is_valid' => true,
            'signer_info' => $request->query('signer') === 'verifier' ? [
                'label' => 'Verifikator/Panitia PMB',
                'name' => $signerName,
                'signed' => true,
                'signed_at' => $registrant->verified_at?->translatedFormat('d F Y, H:i'),
            ] : null,
        ]);
    }

    /**
     * Verifikasi dokumen KRS via QR Code
     * URL: /api/verify/krs/{id}?signer=kaprodi|dosen_wali|mahasiswa
     */
    public function verifyKrs(Request $request, int $id): JsonResponse
    {
        $krs = Krs::with([
            'student.studyProgram',
            'semester.academicYear',
            'advisor',
            'details.course',
        ])->find($id);

        if (! $krs) {
            return response()->json([
                'valid' => false,
                'message' => 'Dokumen KRS tidak ditemukan.',
            ], 404);
        }

        $signer = $request->query('signer', '');

        $signatureMap = [
            'kaprodi' => [
                'label' => 'Ketua Program Studi',
                'signed' => ! is_null($krs->signed_by_kaprodi_at),
                'signed_at' => $krs->signed_by_kaprodi_at ? $krs->signed_by_kaprodi_at->format('d F Y, H:i') : null,
            ],
            'dosen_wali' => [
                'label' => 'Dosen Wali / Pembimbing Akademik',
                'signed' => ! is_null($krs->approved_at),
                'signed_at' => $krs->approved_at ? $krs->approved_at->format('d F Y, H:i') : null,
            ],
            'mahasiswa' => [
                'label' => 'Mahasiswa',
                'signed' => ! is_null($krs->submitted_at),
                'signed_at' => $krs->submitted_at ? $krs->submitted_at->format('d F Y, H:i') : null,
            ],
        ];
        $signatureInfo = isset($signatureMap[$signer]) ? $signatureMap[$signer] : null;

        return response()->json([
            'valid' => true,
            'document' => 'KARTU RENCANA STUDI (KRS)',
            'krs_id' => $krs->id,
            'status' => $krs->status,
            'student' => [
                'name' => $krs->student ? $krs->student->name : null,
                'nim' => $krs->student ? $krs->student->nim : null,
                'study_program' => $krs->student && $krs->student->studyProgram
                    ? $krs->student->studyProgram->name : null,
            ],
            'semester' => $krs->semester ? $krs->semester->name : null,
            'total_credits' => $krs->total_credits,
            'courses_count' => $krs->details->count(),
            'submitted_at' => $krs->submitted_at ? $krs->submitted_at->format('d F Y') : null,
            'approved_at' => $krs->approved_at ? $krs->approved_at->format('d F Y') : null,
            'signed_kaprodi' => $krs->signed_by_kaprodi_at
                ? $krs->signed_by_kaprodi_at->format('d F Y') : null,
            'is_fully_signed' => ! is_null($krs->signed_by_kaprodi_at),
            'signer_info' => $signatureInfo,
        ]);
    }

    /**
     * Verifikasi dokumen RPKPS/RPS via QR Code
     * URL: /api/verify/rpkps/{verification_code}?signer=dosen|lpm|kaprodi
     */
    public function verifyRpkps(Request $request, string $verificationCode): JsonResponse
    {
        $rpkps = Rpkps::with([
            'course.studyProgram',
            'lecturer',
            'academicYear',
            'semester',
            'approvals' => function ($q) {
                $q->orderByDesc('created_at')->limit(5);
            },
        ])->where('verification_code', $verificationCode)->first();

        if (! $rpkps) {
            return response()->json([
                'valid' => false,
                'message' => 'Dokumen RPS tidak ditemukan atau kode verifikasi tidak valid.',
            ], 404);
        }

        $signer = $request->query('signer', '');
        $isElectronicallyApproved = in_array($rpkps->status, ['DISETUJUI', 'DIKUNCI', 'DIARSIPKAN'], true);
        $lpmPosition = LecturerPosition::with('lecturer')
            ->where('position_code', 'KETUA_LPM')
            ->where('is_active', true)
            ->where(function ($query): void {
                $query->where('scope_type', 'institution')
                    ->orWhereNull('scope_type');
            })
            ->latest('id')
            ->first()
            ?? LecturerPosition::with('lecturer')
                ->where('position_code', 'KETUA_LPM')
                ->where('is_active', true)
                ->latest('id')
                ->first();

        $signerMap = [
            'dosen' => [
                'label' => 'Dosen Pengampu Mata Kuliah',
                'name' => $rpkps->lecturer ? $rpkps->lecturer->name : null,
                'signed' => $isElectronicallyApproved,
            ],
            'lpm' => [
                'label' => 'Lembaga Penjaminan Mutu',
                'name' => $lpmPosition?->lecturer?->name,
                'signed' => $isElectronicallyApproved,
            ],
            'kaprodi' => [
                'label' => 'Ketua Program Studi',
                'signed' => $isElectronicallyApproved,
                'name' => null,
            ],
        ];
        $signatureInfo = isset($signerMap[$signer]) ? $signerMap[$signer] : null;

        $approvedByName = null;
        if ($rpkps->approved_by) {
            $approver = User::find($rpkps->approved_by);
            $approvedByName = $approver ? $approver->name : null;
        }

        return response()->json([
            'valid' => true,
            'document' => 'RENCANA PEMBELAJARAN SEMESTER (RPS/RPKPS)',
            'code' => $rpkps->code,
            'status' => $rpkps->status,
            'course' => [
                'code' => $rpkps->course ? $rpkps->course->code : null,
                'name' => $rpkps->course ? $rpkps->course->name : null,
                'credits' => $rpkps->course ? $rpkps->course->credits : null,
                'study_program' => $rpkps->course && $rpkps->course->studyProgram
                    ? $rpkps->course->studyProgram->name : null,
            ],
            'lecturer' => $rpkps->lecturer ? $rpkps->lecturer->name : null,
            'academic_year' => $rpkps->academicYear ? $rpkps->academicYear->name : null,
            'semester' => $rpkps->semester ? $rpkps->semester->name : null,
            'version' => $rpkps->version,
            'approved_at' => $rpkps->approved_at ? $rpkps->approved_at->format('d F Y, H:i') : null,
            'approved_by' => $approvedByName,
            'is_valid' => in_array($rpkps->status, ['DISETUJUI', 'DIKUNCI']),
            'signer_info' => $signatureInfo,
        ]);
    }

    /**
     * Verifikasi Kalender Akademik via QR Code
     * URL: /api/verify/academic-calendar/{id}
     */
    public function verifyAcademicCalendar(int $id): JsonResponse
    {
        // id bisa berupa academic_year_id atau 'all'
        if ($id === 0) {
            return response()->json([
                'valid' => false,
                'message' => 'ID kalender akademik tidak valid.',
            ], 404);
        }

        $academicYear = AcademicYear::find($id);
        if (! $academicYear) {
            return response()->json([
                'valid' => false,
                'message' => 'Tahun akademik tidak ditemukan.',
            ], 404);
        }

        $eventsCount = AcademicCalendar::where('academic_year_id', $id)->count();

        // Ambil WK1 yang menandatangani
        $wk1Position = LecturerPosition::where('position_code', 'WK1')
            ->where('is_active', true)
            ->first();
        $wk1Name = $wk1Position?->lecturer?->display_name ?? null;

        return response()->json([
            'valid' => true,
            'document' => 'KALENDER AKADEMIK',
            'academic_year' => $academicYear->name,
            'events_count' => $eventsCount,
            'signed_by' => $wk1Name,
            'position' => 'Wakil Ketua I Bidang Akademik',
            'is_valid' => true,
        ]);
    }

    /**
     * Verifikasi Surat Keluar via QR Code
     * URL: /api/verify/surat/{token}
     */
    public function verifyOutgoingLetter(string $token): JsonResponse
    {
        $letter = OutgoingLetter::with(['letterType', 'signer.lecturer'])
            ->where('verification_token', $token)
            ->first();

        if (! $letter) {
            return response()->json([
                'valid' => false,
                'message' => 'Surat tidak ditemukan atau token verifikasi tidak valid.',
            ], 404);
        }

        if (! in_array($letter->status, ['DITANDATANGANI', 'TERKIRIM'])) {
            return response()->json([
                'valid' => false,
                'message' => 'Surat belum selesai ditandatangani.',
                'document' => 'SURAT KELUAR',
                'status' => $letter->status,
            ]);
        }

        $signerName = $letter->signer?->lecturer?->display_name ?? $letter->signer?->name ?? '-';

        // Jabatan penandatangan
        $signerPosition = '';
        if ($letter->signer?->lecturer) {
            $pos = LecturerPosition::where('lecturer_id', $letter->signer->lecturer->id)
                ->where('is_active', true)->first();
            $signerPosition = $pos?->position_name ?? '';
        }

        return response()->json([
            'valid' => true,
            'document' => 'SURAT KELUAR',
            'letter_number' => $letter->letter_number,
            'subject' => $letter->subject,
            'letter_type' => $letter->letterType?->name,
            'letter_date' => $letter->letter_date?->format('d F Y'),
            'recipient' => $letter->recipient,
            'signed_by' => $signerName,
            'signer_position' => $signerPosition,
            'signed_at' => $letter->signed_at?->format('d F Y, H:i'),
            'status' => $letter->status,
            'is_valid' => true,
        ]);
    }

    public function verifyMeetingMinutes(string $token): JsonResponse
    {
        $minutes = MeetingMinutes::with(['signer','event'])->where('verification_token',$token)->first();
        if (!$minutes) return response()->json(['valid'=>false,'message'=>'Notulen tidak ditemukan atau token tidak valid.'],404);
        if (!in_array($minutes->status,['DITANDATANGANI','TERKIRIM'])) return response()->json(['valid'=>false,'message'=>'Notulen belum selesai ditandatangani.'],422);
        return response()->json([
            'valid'=>true,'document'=>'NOTULEN RAPAT','document_type'=>'notulen','number'=>$minutes->number,
            'title'=>$minutes->title,'meeting_date'=>$minutes->meeting_date?->format('d F Y'),'location'=>$minutes->location,
            'event'=>$minutes->event?->title,'signed_by'=>$minutes->signer?->name,'signed_at'=>$minutes->signed_at?->format('d F Y, H:i'),
            'status'=>$minutes->status,'is_valid'=>true,
        ]);
    }

    public function verifyClassParticipants(Request $request, string $token): JsonResponse
    {
        return $this->verifyClassDocument($request, $token, 'class-participants', 'PRESENSI KELAS');
    }

    public function verifyClassJournals(Request $request, string $token): JsonResponse
    {
        return $this->verifyClassDocument($request, $token, 'class-journals', 'JURNAL KEGIATAN PEMBELAJARAN');
    }

    private function verifyClassDocument(Request $request, string $token, string $type, string $document): JsonResponse
    {
        $id = OperationalDocumentVerification::id($token);
        $class = $id ? ClassModel::with([
            'course',
            'semester',
            'lecturer',
            'studyProgram.headLecturer',
        ])->find($id) : null;
        if (! $class || ! OperationalDocumentVerification::matches($token, $type, $class->id, 'class-'.$class->id)) {
            return response()->json(['valid' => false, 'message' => 'Dokumen kelas tidak ditemukan atau token verifikasi tidak valid.'], 404);
        }

        $signerInfo = null;
        $signer = $request->query('signer');
        if ($type === 'class-participants' && is_string($signer)) {
            [$label, $lecturer] = match ($signer) {
                'kaprodi' => ['Ketua Program Studi', $class->studyProgram?->headLecturer],
                'dosen' => ['Dosen Pengampu', $class->lecturer],
                default => [null, null],
            };

            if ($label) {
                $signerName = $lecturer?->display_name ?? $lecturer?->full_name;
                $signerInfo = [
                    'label' => $label,
                    'name' => $signerName,
                    'signed' => filled($signerName),
                ];
            }
        }

        return response()->json([
            'valid' => true,
            'document' => $document,
            'document_type' => $type,
            'class' => [
                'course' => $class->course?->code.' - '.$class->course?->name,
                'name' => $class->name,
                'semester' => $class->semester?->name,
                'lecturer' => $class->lecturer?->display_name ?? $class->lecturer?->full_name,
            ],
            'signer_info' => $signerInfo,
            'is_valid' => true,
        ]);
    }

    private function verifyAcademicDocument(Request $request, string $token, string $type): JsonResponse
    {
        $payload = AcademicDocumentVerification::decode($token);
        if (! $payload || ($payload['t'] ?? null) !== $type) {
            return response()->json(['valid' => false, 'message' => 'Token verifikasi dokumen tidak valid.'], 404);
        }

        $student = Student::with(['studyProgram.faculty', 'studyProgram.headLecturer', 'advisor'])->find($payload['s'] ?? null);
        $semesterId = $type === 'khs' ? (int) ($payload['m'] ?? 0) : null;
        $semester = $semesterId ? Semester::find($semesterId) : null;
        if (! $student || ($type === 'khs' && ! $semester)) {
            return response()->json(['valid' => false, 'message' => 'Data dokumen akademik tidak ditemukan.'], 404);
        }

        $grades = StudentGrade::with(['course:id,code,name,credits', 'semester:id,name,start_date'])
            ->where('student_id', $student->id)
            ->when($semesterId, fn ($query) => $query->where('semester_id', $semesterId))
            ->get();

        if (! AcademicDocumentVerification::matches($payload, $type, $student, $grades, $semesterId)) {
            return response()->json([
                'valid' => false,
                'message' => 'Data akademik telah berubah setelah dokumen diterbitkan. Silakan cetak dokumen terbaru.',
            ], 409);
        }

        $totalCredits = $grades->sum(fn ($grade) => (int) ($grade->course?->credits ?? 0));
        $totalPoints = $grades->sum(fn ($grade) => (int) ($grade->course?->credits ?? 0) * (float) ($grade->grade_point ?? 0));
        $calculatedGpa = $totalCredits > 0 ? round($totalPoints / $totalCredits, 2) : 0;
        $summary = $type === 'khs'
            ? DB::table('student_semester_summaries')->where(['student_id' => $student->id, 'semester_id' => $semesterId])->first()
            : DB::table('student_semester_summaries as summaries')
                ->join('semesters', 'semesters.id', '=', 'summaries.semester_id')
                ->where('summaries.student_id', $student->id)
                ->orderByDesc('semesters.start_date')
                ->select('summaries.*')->first();
        $signer = $student->studyProgram?->headLecturer;
        $signedAt = isset($payload['i']) ? Carbon::parse($payload['i'])->translatedFormat('d F Y, H:i') : null;
        $wk1Signer = $request->query('signer') === 'waket1'
            ? LecturerPosition::with('lecturer')
                ->where('position_code', 'WK1')
                ->where('is_active', true)
                ->whereHas('lecturer', fn ($query) => $query->where('status', true))
                ->first()?->lecturer
            : null;
        $signerInfo = match ($request->query('signer')) {
            'kaprodi' => [
                'label' => 'Ketua Program Studi',
                'name' => $signer?->display_name,
                'signed' => $signer !== null && (int) ($payload['g'] ?? 0) === (int) $signer->id,
                'signed_at' => $signedAt,
            ],
            'dosen_wali' => [
                'label' => 'Dosen Pembimbing Akademik',
                'name' => $student->advisor?->display_name,
                'signed' => $student->advisor !== null,
                'signed_at' => $signedAt,
            ],
            'mahasiswa' => [
                'label' => 'Mahasiswa',
                'name' => $student->name,
                'signed' => true,
                'signed_at' => $signedAt,
            ],
            'waket1' => [
                'label' => 'Wakil Ketua I',
                'name' => $wk1Signer?->display_name,
                'signed' => $wk1Signer !== null,
                'signed_at' => $signedAt,
            ],
            default => null,
        };

        return response()->json([
            'valid' => true,
            'document' => $type === 'khs' ? 'KARTU HASIL STUDI (KHS)' : 'TRANSKRIP NILAI AKADEMIK',
            'document_type' => $type,
            'student' => [
                'name' => $student->name,
                'nim' => $student->nim,
                'study_program' => $student->studyProgram?->name,
                'faculty' => $student->studyProgram?->faculty?->name,
            ],
            'semester' => $semester?->name,
            'courses_count' => $grades->count(),
            'total_credits' => $totalCredits,
            'gpa' => $type === 'khs' ? ($summary?->semester_gpa ?? $calculatedGpa) : ($summary?->cumulative_gpa ?? $calculatedGpa),
            'signed_by' => $signer?->display_name,
            'signer_position' => 'Ketua Program Studi',
            'issued_at' => $signedAt,
            'is_valid' => true,
            'signer_info' => $signerInfo,
        ]);
    }
}
