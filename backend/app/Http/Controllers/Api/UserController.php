<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Exports\UserExport;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $users = User::with('roles')
            ->when($request->search, fn($q) => $q->where('name', 'like', "%{$request->search}%")
                ->orWhere('email', 'like', "%{$request->search}%"))
            ->when($request->role, fn($q) => $q->role($request->role))
            ->paginate($request->per_page ?? 15);

        return response()->json($users);
    }

    public function export(Request $request)
    {
        $filename = 'pengguna-' . now()->format('Ymd-His') . '.xlsx';

        return Excel::download(
            new UserExport($request->search, $request->role),
            $filename
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users',
            'username' => 'nullable|string|unique:users',
            'password' => 'required|string|min:8',
            'role'     => 'required|string|exists:roles,name',
        ]);

        $user = User::create([
            'name'     => $validated['name'],
            'email'    => $validated['email'],
            'username' => $validated['username'] ?? null,
            'password' => Hash::make($validated['password']),
        ]);

        $user->assignRole($validated['role']);

        return response()->json([
            'message' => 'User berhasil dibuat.',
            'user'    => $user->load('roles'),
        ], 201);
    }

    public function show(User $user): JsonResponse
    {
        return response()->json($user->load('roles', 'permissions'));
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'name'      => 'sometimes|string|max:255',
            'email'     => "sometimes|email|unique:users,email,{$user->id}",
            'username'  => "sometimes|string|unique:users,username,{$user->id}",
            'password'  => 'sometimes|string|min:8',
            'is_active' => 'sometimes|boolean',
            'role'      => 'sometimes|string|exists:roles,name',
        ]);

        if (isset($validated['password'])) {
            $validated['password'] = Hash::make($validated['password']);
        }

        $user->update(collect($validated)->except('role')->toArray());

        if (isset($validated['role'])) {
            $user->syncRoles([$validated['role']]);
        }

        return response()->json([
            'message' => 'User berhasil diupdate.',
            'user'    => $user->fresh('roles'),
        ]);
    }

    public function destroy(User $user): JsonResponse
    {
        $user->delete();
        return response()->json(['message' => 'User berhasil dihapus.']);
    }

    public function bulkDelete(Request $request): JsonResponse
    {
        $request->validate(['ids' => 'required|array|min:1', 'ids.*' => 'exists:users,id']);
        // Jangan hapus diri sendiri
        $ids = array_filter($request->ids, fn($id) => $id !== auth()->id());
        $count = User::whereIn('id', $ids)->delete();
        return response()->json(['message' => "{$count} pengguna berhasil dihapus."]);
    }

    public function roles(): JsonResponse
    {
        // Hanya tampilkan role yang aktif dipakai (role lama sudah dihapus oleh migration)
        $validRoles = ['SUPER_ADMIN', 'ADMIN_AKADEMIK', 'ADMIN_PMB', 'ADMIN_KEUANGAN', 'ADMIN_DIGILIB', 'ADMIN_UMUM', 'KEPALA_TU', 'DOSEN', 'MAHASISWA', 'ALUMNI'];

        return response()->json(
            Role::whereIn('name', $validRoles)->get(['id', 'name'])
        );
    }

    /** Daftar user ringkas — untuk pilih penerima surat, disposisi, peserta agenda */
    public function list(Request $request): JsonResponse
    {
        $users = User::with([
                'roles',
                'lecturer.activePositions',
                'student',
            ])
            ->where('is_active', true)
            ->where(function ($query) {
                // User non-dosen tetap dapat dipilih jika aktif. User yang
                // terhubung ke data dosen harus memiliki status dosen aktif.
                $query->whereDoesntHave('lecturer')
                    ->orWhereHas('lecturer', fn ($lecturerQuery) => $lecturerQuery->where('status', true));
            })
            ->when($request->search, fn($q) => $q->where(function ($query) use ($request) {
                $query->where('name', 'like', "%{$request->search}%")
                    ->orWhere('email', 'like', "%{$request->search}%");
            }))
            ->select('id', 'name', 'email')
            ->orderBy('name')
            ->get()
            ->map(function ($u) {
                $roles = $u->roles->pluck('name');
                $role = $roles->first() ?? '';
                $label = match (true) {
                    $roles->contains('SUPER_ADMIN') => 'Admin',
                    $roles->contains('ADMIN_AKADEMIK') => 'Admin Akademik',
                    $roles->contains('ADMIN_UMUM') => 'Admin Umum',
                    $roles->contains('ADMIN_PMB') => 'Admin PMB',
                    $roles->contains('ADMIN_KEUANGAN') => 'Admin Keuangan',
                    $roles->contains('KEPALA_TU') => 'Kepala TU',
                    $roles->contains('DOSEN') => 'Dosen',
                    $roles->contains('MAHASISWA') => 'Mahasiswa',
                    $roles->contains('ALUMNI') => 'Alumni',
                    default => $role,
                };

                $positions = $u->lecturer?->activePositions ?? collect();
                $positionName = $positions->pluck('position_name')->filter()->implode(', ');
                if ($positionName) {
                    $label = $positionName;
                }

                return [
                    'id'               => $u->id,
                    'name'             => $u->name,
                    'email'            => $u->email,
                    'role_label'       => $label,
                    'role'             => $role,
                    'roles'            => $roles->values(),
                    'has_position'     => $positions->isNotEmpty(),
                    'is_lecturer'      => $u->lecturer !== null || $roles->contains('DOSEN'),
                    'is_student'       => $u->student !== null || $roles->contains('MAHASISWA'),
                    'study_program_id' => $u->lecturer?->study_program_id ?? $u->student?->study_program_id,
                    'position_name'    => $positionName,
                    'nim'              => $u->student?->nim,
                    'nidn'             => $u->lecturer?->nidn,
                ];
            });

        return response()->json($users);
    }
}
