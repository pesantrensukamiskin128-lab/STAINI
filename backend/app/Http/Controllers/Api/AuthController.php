<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Lecturer;
use App\Models\LoginAttempt;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Throwable;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $request->merge([
            'identifier' => trim((string) ($request->input('identifier') ?: $request->input('email', ''))),
        ]);

        $validated = $request->validate([
            'identifier' => 'required|string|max:255',
            'password' => 'required|string',
        ]);
        $identifier = $validated['identifier'];
        $user = User::query()
            ->where(function ($query) use ($identifier): void {
                $query->where('email', $identifier)
                    ->orWhere('username', $identifier)
                    ->orWhereHas('student', fn ($studentQuery) => $studentQuery->where('nim', $identifier))
                    ->orWhereHas('lecturer', fn ($lecturerQuery) => $lecturerQuery->where('nidn', $identifier));
            })
            ->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            LoginAttempt::record($identifier, false, 'Invalid credentials');
            throw ValidationException::withMessages([
                'identifier' => ['Email, username, NIM, NIDN, atau password salah.'],
            ]);
        }

        Auth::login($user);

        if (! $user->is_active) {
            Auth::logout();
            LoginAttempt::record($identifier, false, 'Account inactive');

            return response()->json(['message' => 'Akun Anda tidak aktif.'], 403);
        }

        // Cek apakah 2FA aktif
        if ($user->two_factor_enabled) {
            // Jangan buat token permanen dulu, minta verifikasi 2FA
            $tempToken = $user->createToken('2fa_pending', ['2fa:verify'], now()->addMinutes(5))->plainTextToken;

            LoginAttempt::record($identifier, true, '2FA pending');

            return response()->json([
                'requires_2fa' => true,
                'temp_token' => $tempToken,
                'message' => 'Masukkan kode 2FA untuk melanjutkan.',
            ]);
        }

        $user->update(['last_login_at' => now()]);
        $token = $user->createToken('auth_token')->plainTextToken;

        LoginAttempt::record($identifier, true);
        AuditLog::record('LOGIN', 'User', $user->id);

        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'username' => $user->username,
                'photo_url' => $this->profilePhotoUrl($user),
                'roles' => $user->getRoleNames(),
                'permissions' => $user->getAllEffectivePermissions(),
                'position_codes' => $user->getActivePositionCodes(),
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $response = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'username' => $user->username,
            'photo_url' => $this->profilePhotoUrl($user),
            'is_active' => $user->is_active,
            'last_login_at' => $user->last_login_at,
            'roles' => $user->getRoleNames(),
            'permissions' => $user->getAllEffectivePermissions(),
            'position_codes' => $user->getActivePositionCodes(),
        ];

        // Sertakan data student/lecturer jika ada
        if ($user->student) {
            $response['student'] = [
                'id' => $user->student->id,
                'nim' => $user->student->nim,
                'name' => $user->student->name,
                'study_program_id' => $user->student->study_program_id,
                'status' => $user->student->status,
            ];
        }
        if ($user->lecturer) {
            $response['lecturer'] = [
                'id' => $user->lecturer->id,
                'name' => $user->lecturer->name,
            ];
        }

        return response()->json($response);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'email' => "sometimes|required|email|unique:users,email,{$user->id}",
            'username' => "sometimes|nullable|string|max:50|unique:users,username,{$user->id}",
            'current_password' => 'required_with:new_password|string',
            'new_password' => 'sometimes|nullable|string|min:8|confirmed',
            'new_password_confirmation' => 'sometimes|nullable|string',
        ]);

        // Verifikasi password lama sebelum ganti password baru
        if (isset($validated['new_password']) && $validated['new_password']) {
            if (! Hash::check($validated['current_password'], $user->password)) {
                return response()->json([
                    'message' => 'Password saat ini tidak sesuai.',
                    'errors' => ['current_password' => ['Password saat ini tidak sesuai.']],
                ], 422);
            }
            $user->password = Hash::make($validated['new_password']);
        }

        $user->name = $validated['name'] ?? $user->name;
        $user->email = $validated['email'] ?? $user->email;
        $user->username = array_key_exists('username', $validated) ? $validated['username'] : $user->username;
        $user->save();

        return response()->json([
            'message' => 'Profil berhasil diperbarui.',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'username' => $user->username,
                'photo_url' => $this->profilePhotoUrl($user),
                'is_active' => $user->is_active,
                'last_login_at' => $user->last_login_at,
                'roles' => $user->getRoleNames(),
                'permissions' => $user->getAllPermissions()->pluck('name'),
                'position_codes' => $user->getActivePositionCodes(),
            ],
        ]);
    }

    public function uploadProfilePhoto(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $request->validate([
            'photo' => 'required|image|mimes:jpeg,png,webp|max:2048',
        ]);

        $path = $request->file('photo')->store('profile-photos', 'public');
        if (! $path) {
            return response()->json(['message' => 'Foto profil gagal disimpan.'], 500);
        }

        $oldPath = $user->photo_path;
        try {
            $user->photo_path = $path;
            $user->save();
        } catch (Throwable $exception) {
            Storage::disk('public')->delete($path);
            throw $exception;
        }

        if ($oldPath && $oldPath !== $path) {
            Storage::disk('public')->delete($oldPath);
        }

        return response()->json([
            'message' => 'Foto profil berhasil diperbarui.',
            'user' => [
                'photo_path' => $user->photo_path,
                'photo_url' => $this->profilePhotoUrl($user),
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Berhasil logout.']);
    }

    public function logoutAll(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();

        return response()->json(['message' => 'Semua sesi berhasil dihapus.']);
    }

    private function profilePhotoUrl(User $user): ?string
    {
        if (! $user->photo_path) {
            return null;
        }

        $url = url(Storage::disk('public')->url($user->photo_path));
        $version = $user->updated_at?->timestamp;

        return $version ? "{$url}?v={$version}" : $url;
    }
}
