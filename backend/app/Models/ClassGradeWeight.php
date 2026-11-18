<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClassGradeWeight extends Model
{
    public const DEFAULT_COMPONENTS = [
        ['key' => 'attendance', 'name' => 'Presensi', 'weight' => 10, 'source' => 'attendance'],
        ['key' => 'tasks', 'name' => 'Tugas', 'weight' => 20, 'source' => 'tasks'],
        ['key' => 'uts', 'name' => 'UTS', 'weight' => 30, 'source' => 'uts'],
        ['key' => 'uas', 'name' => 'UAS', 'weight' => 40, 'source' => 'uas'],
    ];

    protected $fillable = ['class_id', 'assignment_weight', 'exam_weight', 'attendance_weight', 'components'];

    protected $casts = [
        'assignment_weight' => 'integer',
        'exam_weight' => 'integer',
        'attendance_weight' => 'integer',
        'components' => 'array',
    ];

    public function class_(): BelongsTo
    {
        return $this->belongsTo(ClassModel::class, 'class_id');
    }
}
