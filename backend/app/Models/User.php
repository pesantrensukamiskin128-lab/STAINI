<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'username',
        'photo_path',
        'is_active',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function student(): HasOne
    {
        return $this->hasOne(Student::class);
    }

    public function lecturer(): HasOne
    {
        return $this->hasOne(Lecturer::class);
    }

    public function staff(): HasOne
    {
        return $this->hasOne(Staff::class);
    }

    /**
     * Gabungkan permissions dari role Spatie + jabatan struktural dosen.
     * Digunakan di AuthController untuk response login/me.
     */
    public function getAllEffectivePermissions(): array
    {
        // Permissions dari role (Spatie)
        $rolePerms = $this->getAllPermissions()->pluck('name')->toArray();

        // Permissions dari jabatan struktural (jika user adalah dosen)
        $positionPerms = [];
        $lecturer = $this->lecturer;
        if ($lecturer) {
            $positionPerms = LecturerPosition::getPermissionsForLecturer($lecturer->id);
        }

        return collect(array_merge($rolePerms, $positionPerms))->unique()->values()->toArray();
    }

    public function getActivePositionCodes(): array
    {
        $lecturerId = $this->lecturer?->id;
        if (! $lecturerId) {
            return [];
        }

        return LecturerPosition::where('lecturer_id', $lecturerId)
            ->where('is_active', true)
            ->pluck('position_code')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Program studi yang menjadi tanggung jawab aktif Kaprodi/Sekprodi.
     *
     * @return array<int, int>
     */
    public function getManagedStudyProgramIds(): array
    {
        $lecturerId = $this->lecturer?->id;
        if (! $lecturerId) {
            return [];
        }

        return LecturerPosition::where('lecturer_id', $lecturerId)
            ->where('is_active', true)
            ->whereIn('position_code', ['KAPRODI', 'SEKPRODI'])
            ->where('scope_type', 'study_program')
            ->whereNotNull('scope_id')
            ->pluck('scope_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }
}
