<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LecturerWork;
use App\Models\Lecturer;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\LecturerWorkExport;

class LecturerWorkController extends Controller
{
    /** Resolve lecturer_id dari user yang login */
    private function resolveLecturerId($user): ?int
    {
        $id = \App\Models\Lecturer::where('user_id', $user->id)->value('id');
        if (!$id && $user->email) {
            $id = \App\Models\Lecturer::where('email', $user->email)->value('id');
        }
        return $id;
    }

    public function index(Request $request): JsonResponse
    {
        $user    = auth()->user();
        $isAdmin = $user->hasRole('SUPER_ADMIN') || $user->hasRole('ADMIN_AKADEMIK')
                   || $user->hasPermissionTo('karya.verify');

        $query = LecturerWork::with(['lecturer', 'coAuthors'])
            ->when($request->type, fn($q) => $q->where('type', $request->type))
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->when($request->search, fn($q) => $q->where('title', 'like', "%{$request->search}%"))
            ->when($request->year, fn($q) => $q->where('year', $request->year));

        // Dosen hanya lihat karya miliknya
        if (!$isAdmin) {
            $lecturerId = $this->resolveLecturerId($user);
            if ($lecturerId) {
                $query->where('lecturer_id', $lecturerId);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        $data = $query->orderByDesc('created_at')->paginate($request->per_page ?? 15);

        return response()->json($data);
    }

    public function show(LecturerWork $lecturerWork): JsonResponse
    {
        $user    = auth()->user();
        $isAdmin = $user->hasRole('SUPER_ADMIN') || $user->hasRole('ADMIN_AKADEMIK')
                   || $user->hasPermissionTo('karya.verify');

        if (!$isAdmin) {
            $lecturerId = $this->resolveLecturerId($user);
            if ($lecturerId !== $lecturerWork->lecturer_id) {
                return response()->json(['message' => 'Akses ditolak.'], 403);
            }
        }

        return response()->json($lecturerWork->load(['lecturer', 'coAuthors', 'verifiedBy', 'publishedBy']));
    }

    public function searchAuthors(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => 'required|string|min:2|max:100',
        ]);
        $term = trim($validated['search']);
        $like = '%' . addcslashes($term, '%_\\') . '%';

        $ownLecturerId = $this->resolveLecturerId(auth()->user());
        $lecturers = Lecturer::query()
            ->when($ownLecturerId, fn ($query) => $query->where('id', '!=', $ownLecturerId))
            ->where(function ($query) use ($like) {
                $query->where('full_name', 'like', $like)
                    ->orWhere('nidn', 'like', $like);
            })
            ->orderBy('full_name')
            ->limit(10)
            ->get(['id', 'full_name', 'degree_front', 'degree_back', 'nidn'])
            ->map(fn (Lecturer $lecturer) => [
                'type' => 'lecturer',
                'id' => $lecturer->id,
                'name' => $lecturer->name,
                'identifier' => $lecturer->nidn,
            ]);

        $students = Student::query()
            ->where(function ($query) use ($like) {
                $query->where('name', 'like', $like)
                    ->orWhere('nim', 'like', $like);
            })
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'name', 'nim'])
            ->map(fn (Student $student) => [
                'type' => 'student',
                'id' => $student->id,
                'name' => $student->name,
                'identifier' => $student->nim,
            ]);

        return response()->json([
            'data' => $lecturers->concat($students)->values(),
        ]);
    }

    private function validatedCoAuthors(Request $request, int $ownerLecturerId): ?array
    {
        if (!$request->exists('authors')) {
            return null;
        }

        $authors = $request->input('authors');
        if (is_string($authors)) {
            try {
                $authors = json_decode($authors, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw ValidationException::withMessages(['authors' => 'Daftar penulis tidak valid.']);
            }
        }

        $validator = \Illuminate\Support\Facades\Validator::make(
            ['authors' => $authors],
            [
                'authors' => 'present|array|max:50',
                'authors.*.type' => 'required|in:lecturer,student',
                'authors.*.id' => 'required|integer|min:1',
            ]
        );
        $validated = $validator->validate()['authors'];

        $seen = [];
        foreach ($validated as $index => $author) {
            $key = $author['type'] . ':' . $author['id'];
            if (isset($seen[$key])) {
                throw ValidationException::withMessages([
                    "authors.$index.id" => 'Penulis yang sama tidak boleh dipilih lebih dari sekali.',
                ]);
            }
            $seen[$key] = true;

            if ($author['type'] === 'lecturer') {
                if ((int) $author['id'] === $ownerLecturerId) {
                    throw ValidationException::withMessages([
                        "authors.$index.id" => 'Dosen pemilik karya sudah tercatat sebagai penulis utama.',
                    ]);
                }
                $exists = Lecturer::whereKey($author['id'])->exists();
            } else {
                $exists = Student::whereKey($author['id'])->exists();
            }

            if (!$exists) {
                throw ValidationException::withMessages([
                    "authors.$index.id" => 'Penulis yang dipilih tidak ditemukan.',
                ]);
            }
        }

        return $validated;
    }

    private function syncCoAuthors(LecturerWork $work, array $authors): void
    {
        $work->coAuthors()->delete();

        foreach ($authors as $index => $author) {
            $work->coAuthors()->create([
                'lecturer_id' => $author['type'] === 'lecturer' ? $author['id'] : null,
                'student_id' => $author['type'] === 'student' ? $author['id'] : null,
                'author_order' => $index + 2,
            ]);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $user = auth()->user();
        $lecturerId = $this->resolveLecturerId($user);
        if (!$lecturerId) {
            return response()->json(['message' => 'Akun bukan dosen.'], 403);
        }

        $validated = $request->validate([
            'type'           => 'required|in:buku,modul_ajar,hki_paten,penelitian_mandiri,pengabdian_mandiri',
            'title'          => 'required|string|max:500',
            'year'           => 'required|integer|min:2000|max:' . (date('Y') + 1),
            'description'    => 'nullable|string',
            'keywords'       => 'nullable|string|max:500',
            'publisher'      => 'nullable|string|max:255',
            'isbn_issn'      => 'nullable|string|max:100',
            'hki_number'     => 'nullable|string|max:100',
            'published_date' => 'nullable|date',
            'authors'         => 'sometimes|json',
            'main_file'      => 'nullable|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:10240',
            'support_file'   => 'nullable|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:10240',
        ]);

        $authors = $this->validatedCoAuthors($request, $lecturerId) ?? [];
        $data = collect($validated)->except(['main_file', 'support_file', 'authors'])->toArray();
        $data['lecturer_id'] = $lecturerId;
        $data['status']      = 'draft';

        if ($request->hasFile('main_file')) {
            $data['main_file_path'] = $request->file('main_file')->store('lecturer-works', 'public');
        }
        if ($request->hasFile('support_file')) {
            $data['support_file_path'] = $request->file('support_file')->store('lecturer-works', 'public');
        }

        $work = DB::transaction(function () use ($data, $authors) {
            $work = LecturerWork::create($data);
            $this->syncCoAuthors($work, $authors);

            return $work->load(['lecturer', 'coAuthors']);
        });

        return response()->json(['message' => 'Karya berhasil disimpan.', 'data' => $work], 201);
    }

    public function update(Request $request, LecturerWork $lecturerWork): JsonResponse
    {
        $user = auth()->user();
        $lecturerId = $this->resolveLecturerId($user);

        // Hanya pemilik yang bisa edit, dan hanya saat draft atau revisi
        if ($lecturerId !== $lecturerWork->lecturer_id) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }
        if (!in_array($lecturerWork->status, ['draft', 'revisi'])) {
            return response()->json(['message' => 'Karya hanya bisa diedit saat berstatus draft atau revisi.'], 422);
        }

        $validated = $request->validate([
            'type'           => 'sometimes|in:buku,modul_ajar,hki_paten,penelitian_mandiri,pengabdian_mandiri',
            'title'          => 'sometimes|string|max:500',
            'year'           => 'sometimes|integer|min:2000|max:' . (date('Y') + 1),
            'description'    => 'nullable|string',
            'keywords'       => 'nullable|string|max:500',
            'publisher'      => 'nullable|string|max:255',
            'isbn_issn'      => 'nullable|string|max:100',
            'hki_number'     => 'nullable|string|max:100',
            'published_date' => 'nullable|date',
            'authors'         => 'sometimes|json',
            'main_file'      => 'nullable|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:10240',
            'support_file'   => 'nullable|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:10240',
        ]);

        $authors = $this->validatedCoAuthors($request, $lecturerId);
        $data = collect($validated)->except(['main_file', 'support_file', 'authors'])->toArray();

        if ($request->hasFile('main_file')) {
            if ($lecturerWork->main_file_path) Storage::disk('public')->delete($lecturerWork->main_file_path);
            $data['main_file_path'] = $request->file('main_file')->store('lecturer-works', 'public');
        }
        if ($request->hasFile('support_file')) {
            if ($lecturerWork->support_file_path) Storage::disk('public')->delete($lecturerWork->support_file_path);
            $data['support_file_path'] = $request->file('support_file')->store('lecturer-works', 'public');
        }

        DB::transaction(function () use ($lecturerWork, $data, $authors) {
            $lecturerWork->update($data);
            if ($authors !== null) {
                $this->syncCoAuthors($lecturerWork, $authors);
            }
        });

        return response()->json([
            'message' => 'Karya berhasil diupdate.',
            'data' => $lecturerWork->fresh()->load(['lecturer', 'coAuthors']),
        ]);
    }

    public function destroy(LecturerWork $lecturerWork): JsonResponse
    {
        $user = auth()->user();
        $lecturerId = $this->resolveLecturerId($user);
        if ($lecturerId !== $lecturerWork->lecturer_id) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }
        if (!in_array($lecturerWork->status, ['draft', 'revisi'])) {
            return response()->json(['message' => 'Karya hanya bisa dihapus saat berstatus draft atau revisi.'], 422);
        }

        // Hapus file
        if ($lecturerWork->main_file_path) Storage::disk('public')->delete($lecturerWork->main_file_path);
        if ($lecturerWork->support_file_path) Storage::disk('public')->delete($lecturerWork->support_file_path);
        if ($lecturerWork->cover_image_path) Storage::disk('public')->delete($lecturerWork->cover_image_path);

        $lecturerWork->delete();
        return response()->json(['message' => 'Karya berhasil dihapus.']);
    }

    /** Dosen ajukan ke LP2M */
    public function submit(LecturerWork $lecturerWork): JsonResponse
    {
        $user = auth()->user();
        $lecturerId = $this->resolveLecturerId($user);
        if ($lecturerId !== $lecturerWork->lecturer_id) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }
        if (!in_array($lecturerWork->status, ['draft', 'revisi'])) {
            return response()->json(['message' => 'Hanya karya berstatus draft atau revisi yang bisa diajukan.'], 422);
        }

        $lecturerWork->update([
            'status'       => 'diajukan',
            'revision_note'=> null,   // hapus catatan revisi lama
            'submitted_by' => $user->id,
            'submitted_at' => now(),
        ]);

        // Notifikasi ke admin/LP2M (kirim ke semua user yang punya role ADMIN_AKADEMIK)
        $adminUsers = \App\Models\User::role('ADMIN_AKADEMIK')->get();
        foreach ($adminUsers as $admin) {
            \App\Models\AppNotification::send(
                $admin->id,
                'Karya Dosen Diajukan',
                "{$user->name} mengajukan karya: {$lecturerWork->title}",
                'info',
                '/karya-dosen/' . $lecturerWork->id
            );
        }

        return response()->json(['message' => 'Karya berhasil diajukan ke LP2M.']);
    }

    /** LP2M verifikasi atau minta revisi */
    public function verify(Request $request, LecturerWork $lecturerWork): JsonResponse
    {
        $request->validate([
            'action'        => 'required|in:verify,revision',
            'revision_note' => 'nullable|string',
        ]);

        if ($lecturerWork->status !== 'diajukan') {
            return response()->json(['message' => 'Hanya karya berstatus diajukan yang bisa diverifikasi.'], 422);
        }

        if ($request->action === 'verify') {
            $lecturerWork->update([
                'status'      => 'diverifikasi',
                'verified_by' => auth()->id(),
                'verified_at' => now(),
                'revision_note' => null,
            ]);
            $msg = 'Karya Anda telah diverifikasi oleh LP2M dan siap dipublikasikan.';
            $type = 'success';
        } else {
            if (!$request->revision_note) {
                return response()->json(['message' => 'Catatan revisi wajib diisi.'], 422);
            }
            $lecturerWork->update([
                'status'        => 'revisi',
                'revision_note' => $request->revision_note,
            ]);
            $msg  = "Karya Anda memerlukan revisi. Catatan: {$request->revision_note}";
            $type = 'warning';
        }

        // Notifikasi ke dosen
        if ($lecturerWork->lecturer?->user_id) {
            \App\Models\AppNotification::send(
                $lecturerWork->lecturer->user_id,
                'Status Karya Dosen',
                $msg, $type,
                '/karya-dosen/' . $lecturerWork->id
            );
        }

        return response()->json(['message' => 'Verifikasi berhasil.', 'data' => $lecturerWork->fresh()]);
    }

    /** LP2M publikasi ke repository */
    public function publish(Request $request, LecturerWork $lecturerWork): JsonResponse
    {
        if ($lecturerWork->status !== 'diverifikasi') {
            return response()->json(['message' => 'Hanya karya berstatus diverifikasi yang bisa dipublikasikan.'], 422);
        }

        $request->validate([
            'repository_url' => 'nullable|string|max:500',
            'cover_image'    => 'nullable|file|mimes:jpg,jpeg,png,webp|max:2048',
        ]);

        $coverPath = $lecturerWork->cover_image_path;
        if ($request->hasFile('cover_image')) {
            if ($coverPath) Storage::disk('public')->delete($coverPath);
            $coverPath = $request->file('cover_image')->store('lecturer-work-covers', 'public');
        }

        $lecturerWork->update([
            'status'           => 'dipublikasikan',
            'repository_url'   => $request->repository_url,
            'cover_image_path' => $coverPath,
            'published_by'     => auth()->id(),
            'published_at'     => now(),
        ]);

        // Notifikasi ke dosen
        if ($lecturerWork->lecturer?->user_id) {
            \App\Models\AppNotification::send(
                $lecturerWork->lecturer->user_id,
                '🎉 Karya Dipublikasikan',
                "Karya Anda \"{$lecturerWork->title}\" telah dipublikasikan ke repository.",
                'success',
                '/karya-dosen/' . $lecturerWork->id
            );
        }

        return response()->json(['message' => 'Karya berhasil dipublikasikan.']);
    }

    /** Statistik untuk dashboard */
    public function stats(): JsonResponse
    {
        $user    = auth()->user();
        $isAdmin = $user->hasRole('SUPER_ADMIN') || $user->hasRole('ADMIN_AKADEMIK')
                   || $user->hasPermissionTo('karya.verify');

        $query = LecturerWork::query();
        if (!$isAdmin) {
            $lecturerId = $this->resolveLecturerId($user);
            if ($lecturerId) {
                $query->where('lecturer_id', $lecturerId);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        return response()->json([
            'total'          => (clone $query)->count(),
            'draft'          => (clone $query)->where('status', 'draft')->count(),
            'diajukan'       => (clone $query)->where('status', 'diajukan')->count(),
            'revisi'         => (clone $query)->where('status', 'revisi')->count(),
            'diverifikasi'   => (clone $query)->where('status', 'diverifikasi')->count(),
            'dipublikasikan' => (clone $query)->where('status', 'dipublikasikan')->count(),
        ]);
    }

    public function exportExcel(Request $request)
    {
        $user = auth()->user();
        $isAdmin = $user->hasRole('SUPER_ADMIN') || $user->hasRole('ADMIN_AKADEMIK')
            || $user->hasPermissionTo('karya.verify');
        $query = LecturerWork::with(['lecturer', 'coAuthors.lecturer', 'coAuthors.student'])
            ->when($request->type, fn ($q) => $q->where('type', $request->type))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->search, fn ($q) => $q->where('title', 'like', "%{$request->search}%"))
            ->when($request->year, fn ($q) => $q->where('year', $request->year));
        if (!$isAdmin) {
            $lecturerId = $this->resolveLecturerId($user);
            $query->where('lecturer_id', $lecturerId ?: 0);
        }

        return Excel::download(
            new LecturerWorkExport($query->orderByDesc('created_at')->get()),
            'karya-dosen.xlsx'
        );
    }

    public function templateExcel()
    {
        return Excel::download(new LecturerWorkExport(template: true), 'template-karya-dosen.xlsx');
    }

    public function importExcel(Request $request): JsonResponse
    {
        $user = auth()->user();
        $isAdmin = $user->hasRole('SUPER_ADMIN') || $user->hasRole('ADMIN_AKADEMIK')
            || $user->hasPermissionTo('karya.verify');
        $lecturerId = $this->resolveLecturerId($user);
        $request->validate(['file' => 'required|file|mimes:xlsx,xls,csv|max:10240']);
        $sheets = Excel::toArray([], $request->file('file'));
        $rows = collect($sheets[0] ?? []);
        $header = collect(array_map(fn ($value) => strtolower(trim((string) $value)), $rows->shift() ?? []));
        $rows = $rows->map(fn ($row) => $header->combine(array_pad($row, $header->count(), null))->all())
            ->filter(fn ($row) => trim((string) ($row['title'] ?? '')) !== '')
            ->values();
        if ($header->isEmpty() || !$header->contains('title')) {
            throw \Illuminate\Validation\ValidationException::withMessages(['file' => 'Kolom title wajib tersedia pada template.']);
        }

        $created = 0;
        DB::transaction(function () use ($rows, $isAdmin, $lecturerId, &$created): void {
            foreach ($rows as $index => $row) {
                $rowNumber = $index + 2;
                $ownerId = $lecturerId;
                if ($isAdmin && trim((string) ($row['lecturer_nidn'] ?? '')) !== '') {
                    $ownerId = \App\Models\Lecturer::where('nidn', trim((string) $row['lecturer_nidn']))->value('id');
                    if (!$ownerId) {
                        throw \Illuminate\Validation\ValidationException::withMessages([
                            'file' => "NIDN pada baris {$rowNumber} tidak ditemukan.",
                        ]);
                    }
                }
                if (!$ownerId) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'file' => "Pemilik karya pada baris {$rowNumber} tidak dapat ditentukan.",
                    ]);
                }
                $type = strtolower(trim((string) ($row['type'] ?? '')));
                if (!array_key_exists($type, LecturerWork::TYPE_LABELS)) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'file' => "Jenis karya pada baris {$rowNumber} tidak valid.",
                    ]);
                }
                $year = (int) ($row['year'] ?? 0);
                if ($year < 2000 || $year > date('Y') + 1) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'file' => "Tahun pada baris {$rowNumber} tidak valid.",
                    ]);
                }
                $coAuthors = $this->resolveImportedCoAuthors(
                    (string) ($row['co_authors'] ?? ''),
                    (int) $ownerId,
                    $rowNumber
                );
                $work = LecturerWork::create([
                    'lecturer_id' => $ownerId,
                    'type' => $type,
                    'title' => trim((string) $row['title']),
                    'year' => $year,
                    'description' => $row['description'] ?? null,
                    'keywords' => $row['keywords'] ?? null,
                    'publisher' => $row['publisher'] ?? null,
                    'isbn_issn' => $row['isbn_issn'] ?? null,
                    'hki_number' => $row['hki_number'] ?? null,
                    'published_date' => !empty($row['published_date']) ? $row['published_date'] : null,
                    'repository_url' => $row['repository_url'] ?? null,
                    'status' => 'draft',
                ]);
                foreach ($coAuthors as $authorIndex => $author) {
                    $work->coAuthors()->create([
                        'lecturer_id' => $author['type'] === 'dosen' ? $author['id'] : null,
                        'student_id' => $author['type'] === 'mahasiswa' ? $author['id'] : null,
                        'author_order' => $authorIndex + 2,
                    ]);
                }
                $created++;
            }
        });

        return response()->json(['message' => "{$created} karya berhasil diimport sebagai draft.", 'created' => $created]);
    }

    private function resolveImportedCoAuthors(string $value, int $ownerId, int $rowNumber): array
    {
        $value = trim($value);
        if ($value === '') {
            return [];
        }

        $authors = [];
        $seen = [];
        foreach (explode(';', $value) as $token) {
            $parts = array_map('trim', explode(':', $token, 2));
            if (count($parts) !== 2 || $parts[1] === '') {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'file' => "Format co_authors pada baris {$rowNumber} tidak valid. Gunakan dosen:NIDN atau mahasiswa:NIM.",
                ]);
            }

            [$type, $identifier] = $parts;
            $type = strtolower($type);
            $authorType = str_starts_with($type, 'dosen') ? 'dosen' : 'mahasiswa';
            if (in_array($type, ['dosen', 'dosen_id'], true)) {
                $lecturerQuery = \App\Models\Lecturer::query();
                if ($type === 'dosen_id') {
                    $lecturerQuery->whereKey($identifier);
                } else {
                    $lecturerQuery->where('nidn', $identifier);
                }
                $authorId = $lecturerQuery->value('id');
                if ((int) $authorId === $ownerId) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'file' => "Penulis dosen pada baris {$rowNumber} adalah pemilik karya; pemilik sudah menjadi penulis utama.",
                    ]);
                }
            } elseif (in_array($type, ['mahasiswa', 'mahasiswa_id'], true)) {
                $studentQuery = Student::query();
                if ($type === 'mahasiswa_id') {
                    $studentQuery->whereKey($identifier);
                } else {
                    $studentQuery->where('nim', $identifier);
                }
                $authorId = $studentQuery->value('id');
            } else {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'file' => "Jenis co_authors pada baris {$rowNumber} tidak valid. Gunakan dosen, dosen_id, mahasiswa, atau mahasiswa_id.",
                ]);
            }

            if (!$authorId) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'file' => "NIDN/NIM co_authors pada baris {$rowNumber} tidak ditemukan: {$identifier}.",
                ]);
            }

            $key = $authorType . ':' . $authorId;
            if (isset($seen[$key])) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'file' => "Penulis co_authors pada baris {$rowNumber} dipilih lebih dari satu kali: {$identifier}.",
                ]);
            }
            $seen[$key] = true;
            $authors[] = ['type' => $authorType, 'id' => (int) $authorId];
        }

        return $authors;
    }
}
