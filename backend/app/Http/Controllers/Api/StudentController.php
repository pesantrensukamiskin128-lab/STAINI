<?php

namespace App\Http\Controllers\Api;

use App\Exports\StudentExport;
use App\Http\Controllers\Controller;
use App\Imports\StudentImport;
use App\Models\LecturerPosition;
use App\Models\Student;
use App\Models\StudentAddress;
use App\Models\StudentDocument;
use App\Models\StudentParent;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

class StudentController extends Controller
{
    public function myProfile(Request $request): JsonResponse
    {
        $student = $request->user()?->student;
        if (! $student) {
            return response()->json(['message' => 'Akun ini belum terhubung dengan data mahasiswa.'], 404);
        }

        return response()->json($this->biodataPayload($student));
    }

    public function showBiodata(Request $request, Student $student): JsonResponse
    {
        $this->authorizeBiodataAccess($request, $student);

        return response()->json($this->biodataPayload($student));
    }

    private function biodataPayload(Student $student): array
    {
        $student->load(['profile', 'domicileAddress', 'parents', 'educationHistories', 'documents']);
        $parents = $student->parents->keyBy('relation');
        $education = $student->educationHistories
            ->where('level', 'SMA/MA/SMK')
            ->sortByDesc('id')
            ->first();
        $documents = $student->documents->keyBy('type');

        return [
            'student' => array_merge(
                $student->only(['name', 'gender', 'birth_place', 'email', 'phone', 'home_phone']),
                ['birth_date' => $student->birth_date?->format('Y-m-d')]
            ),
            'profile' => $student->profile?->only(['religion', 'nik', 'nisn', 'blood_type', 'nationality']),
            'address' => $student->domicileAddress?->only(['address', 'village', 'district', 'city', 'province', 'postal_code', 'rt', 'rw', 'hamlet']),
            'parents' => [
                'father' => $parents->get('Ayah')?->only(['name', 'nik', 'birth_date', 'occupation', 'phone', 'email', 'education', 'income', 'is_alive']),
                'mother' => $parents->get('Ibu')?->only(['name', 'nik', 'birth_date', 'occupation', 'phone', 'email', 'education', 'income', 'is_alive']),
                'guardian' => $parents->get('Wali')?->only(['name', 'nik', 'birth_date', 'occupation', 'phone', 'email', 'education', 'income', 'is_alive']),
            ],
            'education' => $education?->only(['institution_name', 'institution_address', 'major', 'graduation_year', 'diploma_number']),
            'documents' => [
                'diploma_link' => $documents->get('IJAZAH')?->file_url,
                'family_card_link' => $documents->get('KARTU_KELUARGA')?->file_url
                    ?? $documents->get('KK')?->file_url,
                'identity_link' => $documents->get('KTP')?->file_url,
            ],
        ];
    }

    public function updateMyProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $student = $user?->student;
        if (! $student) {
            return response()->json(['message' => 'Akun ini belum terhubung dengan data mahasiswa.'], 404);
        }

        return $this->saveBiodata($request, $student, $user);
    }

    public function updateBiodata(Request $request, Student $student): JsonResponse
    {
        $this->authorizeBiodataAccess($request, $student);

        return $this->saveBiodata($request, $student, $student->user);
    }

    private function saveBiodata(Request $request, Student $student, ?User $user): JsonResponse
    {
        $email = trim((string) $request->input('student.email', ''));
        $emailUnchanged = $student->email !== null
            && strcasecmp($email, trim($student->email)) === 0;
        $emailOwnedByAnotherUser = User::query()
            ->where('email', $email)
            ->when($user, fn ($query) => $query->where('id', '!=', $user->id))
            ->exists();
        $syncAccountEmail = $user && ! ($emailUnchanged && $emailOwnedByAnotherUser);
        $emailRules = [
            'required',
            'email',
            'max:255',
            Rule::unique('students', 'email')->ignore($student->id),
        ];
        if (! ($emailUnchanged && $emailOwnedByAnotherUser)) {
            $emailRules[] = Rule::unique('users', 'email')->ignore($user?->id);
        }

        $validated = $request->validate([
            'student.name' => 'required|string|max:255',
            'student.gender' => 'nullable|in:L,P',
            'student.birth_place' => 'nullable|string|max:100',
            'student.birth_date' => 'nullable|date|before_or_equal:today',
            'student.email' => $emailRules,
            'student.phone' => 'nullable|string|max:20',
            'student.home_phone' => 'nullable|string|max:30',
            'profile.religion' => 'nullable|string|max:50',
            'profile.nik' => 'nullable|string|max:20',
            'profile.nisn' => 'nullable|string|max:20',
            'profile.blood_type' => 'nullable|in:A,B,AB,O',
            'profile.nationality' => 'nullable|string|max:50',
            'address.address' => 'nullable|string|max:2000',
            'address.village' => 'nullable|string|max:100',
            'address.district' => 'nullable|string|max:100',
            'address.city' => 'nullable|string|max:100',
            'address.province' => 'nullable|string|max:100',
            'address.postal_code' => 'nullable|string|max:10',
            'address.rt' => 'nullable|string|max:5',
            'address.rw' => 'nullable|string|max:5',
            'address.hamlet' => 'nullable|string|max:100',
            'parents' => 'required|array',
            'parents.father' => 'nullable|array',
            'parents.father.name' => 'nullable|string|max:255',
            'parents.father.nik' => 'nullable|string|max:20',
            'parents.father.birth_date' => 'nullable|date|before_or_equal:today',
            'parents.father.occupation' => 'nullable|string|max:100',
            'parents.father.phone' => 'nullable|string|max:20',
            'parents.father.email' => 'nullable|email|max:255',
            'parents.father.education' => 'nullable|string|max:50',
            'parents.father.income' => 'nullable|string|max:50',
            'parents.father.is_alive' => 'nullable|boolean',
            'parents.mother' => 'nullable|array',
            'parents.mother.name' => 'nullable|string|max:255',
            'parents.mother.nik' => 'nullable|string|max:20',
            'parents.mother.birth_date' => 'nullable|date|before_or_equal:today',
            'parents.mother.occupation' => 'nullable|string|max:100',
            'parents.mother.phone' => 'nullable|string|max:20',
            'parents.mother.email' => 'nullable|email|max:255',
            'parents.mother.education' => 'nullable|string|max:50',
            'parents.mother.income' => 'nullable|string|max:50',
            'parents.mother.is_alive' => 'nullable|boolean',
            'parents.guardian' => 'nullable|array',
            'parents.guardian.name' => 'nullable|string|max:255',
            'parents.guardian.nik' => 'nullable|string|max:20',
            'parents.guardian.birth_date' => 'nullable|date|before_or_equal:today',
            'parents.guardian.occupation' => 'nullable|string|max:100',
            'parents.guardian.phone' => 'nullable|string|max:20',
            'parents.guardian.email' => 'nullable|email|max:255',
            'parents.guardian.education' => 'nullable|string|max:50',
            'parents.guardian.income' => 'nullable|string|max:50',
            'parents.guardian.is_alive' => 'nullable|boolean',
            'education.institution_name' => 'nullable|string|max:255',
            'education.institution_address' => 'nullable|string|max:255',
            'education.major' => 'nullable|string|max:255',
            'education.graduation_year' => 'nullable|integer|between:1900,'.now()->year,
            'education.diploma_number' => 'nullable|string|max:100',
            'documents.diploma_link' => 'nullable|url|max:255',
            'documents.family_card_link' => 'nullable|url|max:255',
            'documents.identity_link' => 'nullable|url|max:255',
        ], [
            'student.email.unique' => 'Email tersebut sudah digunakan akun lain. Gunakan email yang berbeda.',
        ]);

        DB::transaction(function () use ($validated, $student, $user, $syncAccountEmail): void {
            $studentData = $validated['student'];
            $student->fill([
                'name' => $studentData['name'],
                'gender' => $studentData['gender'] ?? null,
                'birth_place' => $studentData['birth_place'] ?? null,
                'birth_date' => $studentData['birth_date'] ?? null,
                'email' => $studentData['email'],
                'phone' => $studentData['phone'] ?? null,
                'home_phone' => $studentData['home_phone'] ?? null,
            ])->save();
            if ($user) {
                $userData = ['name' => $studentData['name']];
                if ($syncAccountEmail) {
                    $userData['email'] = $studentData['email'];
                }
                $user->fill($userData)->save();
            }

            $profileData = $validated['profile'] ?? [];
            $profileData['nationality'] = ($profileData['nationality'] ?? null) ?: 'Indonesia';

            $student->profile()->updateOrCreate(
                ['student_id' => $student->id],
                array_merge([
                    'religion' => null,
                    'nik' => null,
                    'nisn' => null,
                    'blood_type' => null,
                ], $profileData)
            );

            StudentAddress::updateOrCreate(
                ['student_id' => $student->id, 'type' => 'Domisili'],
                array_merge([
                    'address' => null,
                    'village' => null,
                    'district' => null,
                    'city' => null,
                    'province' => null,
                    'postal_code' => null,
                    'rt' => null,
                    'rw' => null,
                    'hamlet' => null,
                ], $validated['address'] ?? [])
            );

            foreach (['father' => 'Ayah', 'mother' => 'Ibu', 'guardian' => 'Wali'] as $key => $relation) {
                if (! array_key_exists($key, $validated['parents'])) {
                    continue;
                }
                $parentData = $validated['parents'][$key] ?? [];
                if (empty($parentData['name'])) {
                    StudentParent::where('student_id', $student->id)->where('relation', $relation)->delete();
                    continue;
                }

                $student->parents()->updateOrCreate(
                    ['relation' => $relation],
                    array_merge($parentData, ['relation' => $relation])
                );
            }

            if (array_key_exists('education', $validated)) {
                $educationData = $validated['education'] ?? [];
                if (! empty($educationData['institution_name'])) {
                    $educationHistory = $student->educationHistories()
                        ->where('level', 'SMA/MA/SMK')
                        ->orderByDesc('id')
                        ->first();
                    $educationHistory
                        ? $educationHistory->update($educationData)
                        : $student->educationHistories()->create(array_merge($educationData, ['level' => 'SMA/MA/SMK']));
                } else {
                    $student->educationHistories()->where('level', 'SMA/MA/SMK')->delete();
                }
            }

            foreach ([
                'diploma_link' => ['IJAZAH', 'Ijazah'],
                'family_card_link' => ['KK', 'Kartu Keluarga'],
                'identity_link' => ['KTP', 'KTP / Identitas'],
            ] as $key => [$type, $name]) {
                if (! array_key_exists($key, $validated['documents'] ?? [])) {
                    continue;
                }
                $link = $validated['documents'][$key] ?? null;
                $document = StudentDocument::query()
                    ->where('student_id', $student->id)
                    ->whereIn('type', $key === 'family_card_link' ? ['KARTU_KELUARGA', 'KK'] : [$type])
                    ->orderByRaw('CASE WHEN type = ? THEN 0 ELSE 1 END', [$type])
                    ->first()
                    ?? new StudentDocument(['student_id' => $student->id, 'type' => $type]);
                if (! $link && ! $document->exists) {
                    continue;
                }
                if ($document->file_url === $link) {
                    continue;
                }
                $document->name = $name;
                $document->file_url = $link;
                $document->is_verified = false;
                $document->verified_by = null;
                $document->verified_at = null;
                $document->save();
            }
        });

        return response()->json([
            'message' => $emailUnchanged && $emailOwnedByAnotherUser
                ? 'Biodata berhasil diperbarui. Email tersebut tersimpan sebagai email kontak dan tidak mengubah email login yang sudah digunakan akun lain.'
                : 'Biodata berhasil diperbarui.',
            'data' => $this->biodataPayload($student->fresh()),
        ]);
    }

    private function authorizeBiodataAccess(Request $request, Student $student): void
    {
        $user = $request->user();
        abort_unless($user && $user->hasAnyRole([
            'SUPER_ADMIN', 'ADMIN_AKADEMIK', 'ADMIN_UMUM', 'KAPRODI', 'SEKPRODI',
        ]), 403);

        if ($user->hasAnyRole(['SUPER_ADMIN', 'ADMIN_AKADEMIK', 'ADMIN_UMUM'])) {
            return;
        }

        $managedStudyProgramIds = $user->getManagedStudyProgramIds();
        abort_if($managedStudyProgramIds === [], 403, 'Anda tidak memiliki cakupan program studi untuk mengelola biodata.');
        abort_unless(
            in_array((int) $student->study_program_id, $managedStudyProgramIds, true),
            403,
            'Mahasiswa berada di luar cakupan program studi Anda.'
        );
    }



    /** Riwayat akademik milik mahasiswa yang sedang login. */
    public function myAcademicHistory(Request $request): JsonResponse
    {
        $student = $request->user()?->student;
        if (! $student) {
            return response()->json(['message' => 'Akun ini belum terhubung dengan data mahasiswa.'], 404);
        }

        $student->load([
            'studyProgram:id,code,name',
            'semesterSummaries.semester:id,name,type,start_date,end_date',
            'statusHistories.semester:id,name,type,start_date,end_date',
        ]);

        $summaries = $student->semesterSummaries
            ->sortByDesc(fn ($summary) => $summary->semester?->start_date?->timestamp ?? 0)
            ->values();
        $statusHistories = $student->statusHistories
            ->sortByDesc(fn ($history) => $history->semester?->start_date?->timestamp ?? 0)
            ->values();

        return response()->json([
            'student' => $student->only(['id', 'nim', 'name', 'status', 'current_semester']),
            'study_program' => $student->studyProgram,
            'summaries' => $summaries,
            'status_histories' => $statusHistories,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        // Jika user adalah Kaprodi, filter hanya mahasiswa prodi-nya
        $kaprodiProdiId = $this->getKaprodiProdiId();

        $data = Student::with(['studyProgram.faculty', 'advisor', 'academicYear'])
            ->when($kaprodiProdiId, fn ($q) => $q->where('study_program_id', $kaprodiProdiId))
            ->when($request->search, fn ($q) => $q->where(fn ($query) => $query
                ->where('name', 'like', "%{$request->search}%")
                ->orWhere('nim', 'like', "%{$request->search}%")))
            ->when($request->study_program_id && ! $kaprodiProdiId, fn ($q) => $q->where('study_program_id', $request->study_program_id))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->entry_year, fn ($q) => $q->where('entry_year', $request->entry_year))
            ->when($request->current_semester, fn ($q) => $q->where('current_semester', $request->current_semester))
            ->orderByDesc('entry_year')
            ->orderByDesc('nim')
            ->paginate($request->per_page ?? 15);

        return response()->json($data);
    }

    public function advisorOptions(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user, 403);

        $isAdmin = $user->hasAnyRole(['SUPER_ADMIN', 'ADMIN_AKADEMIK', 'ADMIN_UMUM']);
        $studyProgramIds = $isAdmin ? [] : $user->getManagedStudyProgramIds();
        abort_unless($isAdmin || $studyProgramIds !== [], 403, 'Anda tidak memiliki cakupan program studi untuk melihat mahasiswa.');

        $perPage = min(max((int) $request->input('per_page', 20), 1), 50);
        $students = Student::query()
            ->with(['studyProgram:id,code,name', 'advisor:id,full_name,degree_front,degree_back'])
            ->when(! $isAdmin, fn ($query) => $query->whereIn('study_program_id', $studyProgramIds))
            ->when($request->search, function ($query, $search): void {
                $query->where(function ($studentQuery) use ($search): void {
                    $studentQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('nim', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('entry_year')
            ->orderByDesc('nim')
            ->paginate($perPage);

        return response()->json($students);
    }

    /** Ambil prodi ID jika user saat ini adalah Kaprodi (bukan admin) */
    private function getKaprodiProdiId(): ?int
    {
        $user = auth()->user();
        if ($user->hasRole('SUPER_ADMIN') || $user->hasRole('ADMIN_AKADEMIK')) {
            return null; // Admin bisa lihat semua
        }
        $lecturer = $user->lecturer;
        if (! $lecturer) {
            return null;
        }

        $position = LecturerPosition::where('lecturer_id', $lecturer->id)
            ->where('is_active', true)
            ->whereIn('position_code', ['KAPRODI', 'SEKPRODI'])
            ->where('scope_type', 'study_program')
            ->first();

        return $position?->scope_id;
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'study_program_id' => 'required|exists:study_programs,id',
            'academic_year_id' => 'nullable|exists:academic_years,id',
            'advisor_id' => 'nullable|exists:lecturers,id',
            'nim' => 'required|string|max:20|unique:students',
            'name' => 'required|string|max:255',
            'gender' => 'nullable|in:L,P',
            'birth_place' => 'nullable|string|max:100',
            'birth_date' => 'nullable|date',
            'email' => 'nullable|email',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string',
            'origin_school' => 'nullable|string|max:255',
            'entry_year' => 'nullable|integer|digits:4',
            'status' => ['sometimes', Rule::in(Student::STATUSES)],
            'class_type' => ['sometimes', Rule::in(Student::CLASS_TYPES)],
        ]);

        // Buat akun user otomatis
        $email = $validated['email'] ?? $validated['nim'].'@student.jawami.ac.id';
        $user = User::create([
            'name' => $validated['name'],
            'email' => $email,
            'username' => $validated['nim'],
            'password' => Hash::make($validated['nim']),
        ]);
        $user->assignRole('MAHASISWA');
        $validated['user_id'] = $user->id;

        $student = Student::create($validated);

        // Kirim notifikasi ke dosen wali jika ditunjuk
        if (! empty($validated['advisor_id'])) {
            NotificationService::advisorAssigned($validated['advisor_id'], $student->name, $student->nim);
        }

        return response()->json([
            'message' => 'Mahasiswa berhasil ditambahkan.',
            'data' => $student->load('studyProgram'),
        ], 201);
    }

    public function show(Student $student): JsonResponse
    {
        return response()->json($student->load([
            'studyProgram.faculty',
            'advisor',
            'academicYear',
            'user',
            'profile',
            'addresses',
            'parents',
            'documents',
            'educationHistories',
            'statusHistories.semester',
            'semesterSummaries.semester',
            'financialRecords.semester',
            'pmbRegistrant.period',
            'pmbRegistrant.path',
            'pmbRegistrant.selectionResult',
        ]));
    }

    public function update(Request $request, Student $student): JsonResponse
    {
        $validated = $request->validate([
            'study_program_id' => 'sometimes|exists:study_programs,id',
            'academic_year_id' => 'nullable|exists:academic_years,id',
            'advisor_id' => 'nullable|exists:lecturers,id',
            'name' => 'sometimes|string|max:255',
            'gender' => 'nullable|in:L,P',
            'birth_place' => 'nullable|string|max:100',
            'birth_date' => 'nullable|date',
            'email' => 'nullable|email',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string',
            'origin_school' => 'nullable|string|max:255',
            'entry_year' => 'nullable|integer|digits:4',
            'status' => ['sometimes', Rule::in(Student::STATUSES)],
            'current_semester' => 'nullable|integer|min:1|max:14',
            'class_type' => ['sometimes', Rule::in(Student::CLASS_TYPES)],
        ]);

        $oldAdvisorId = $student->advisor_id;
        $student->update($validated);

        // Kirim notifikasi jika dosen wali berubah
        if (isset($validated['advisor_id']) && $validated['advisor_id'] && $validated['advisor_id'] != $oldAdvisorId) {
            NotificationService::advisorAssigned($validated['advisor_id'], $student->name, $student->nim);
        }

        return response()->json([
            'message' => 'Data mahasiswa berhasil diupdate.',
            'data' => $student->fresh('studyProgram'),
        ]);
    }

    public function updateAdvisor(Request $request, Student $student): JsonResponse
    {
        $user = $request->user();
        abort_unless($user, 403);

        if (! $user->hasAnyRole(['SUPER_ADMIN', 'ADMIN_AKADEMIK', 'ADMIN_UMUM'])) {
            $managedStudyProgramIds = $user->getManagedStudyProgramIds();
            abort_if($managedStudyProgramIds === [], 403, 'Anda tidak memiliki cakupan program studi untuk mengubah pembimbing.');
            abort_unless(
                in_array((int) $student->study_program_id, $managedStudyProgramIds, true),
                403,
                'Mahasiswa berada di luar cakupan program studi Anda.'
            );
        }

        $validated = $request->validate([
            'advisor_id' => [
                'present',
                'nullable',
                Rule::exists('lecturers', 'id')->where('status', true),
            ],
        ]);

        $previousAdvisorId = $student->advisor_id;
        $student->update(['advisor_id' => $validated['advisor_id']]);

        if ($validated['advisor_id'] && (int) $validated['advisor_id'] !== (int) $previousAdvisorId) {
            NotificationService::advisorAssigned((int) $validated['advisor_id'], $student->name, $student->nim);
        }

        return response()->json([
            'message' => 'Pembimbing akademik berhasil diperbarui.',
            'data' => $student->fresh('advisor'),
        ]);
    }

    public function destroy(Student $student): JsonResponse
    {
        $student->delete();

        return response()->json(['message' => 'Data mahasiswa berhasil dihapus.']);
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $request->validate(['ids' => 'required|array|min:1', 'ids.*' => 'exists:students,id']);
        $count = Student::whereIn('id', $request->ids)->delete();

        return response()->json(['message' => "{$count} data mahasiswa berhasil dihapus."]);
    }

    public function export(Request $request)
    {
        $filename = 'mahasiswa-'.now()->format('Ymd-His').'.xlsx';

        return Excel::download(
            new StudentExport($request->study_program_id, $request->status, $request->entry_year, $request->current_semester),
            $filename
        );
    }

    public function import(Request $request): JsonResponse
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,xls,csv|max:5120']);

        $import = new StudentImport;
        Excel::import($import, $request->file('file'));

        $errors = collect($import->errors())->map(fn ($e) => $e->getMessage())->values();

        return response()->json([
            'message' => 'Import selesai.'.($errors->count() ? ' Beberapa baris dilewati.' : ''),
            'errors' => $errors,
        ]);
    }
}
