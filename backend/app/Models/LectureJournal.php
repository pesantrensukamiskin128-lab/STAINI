<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LectureJournal extends Model
{
    protected $hidden = ['attendance_token_hash'];
    protected $fillable = ['class_id', 'meeting_number', 'meeting_date', 'topic', 'description', 'learning_activity', 'materials_note', 'status', 'lecturer_id', 'delivery_mode', 'latitude', 'longitude', 'photo_path', 'attendance_token_hash', 'attendance_opens_at', 'attendance_closes_at'];
    protected $casts = ['meeting_date' => 'date:Y-m-d', 'attendance_opens_at' => 'datetime', 'attendance_closes_at' => 'datetime', 'reviewed_at' => 'datetime'];
    public function class_(): BelongsTo { return $this->belongsTo(ClassModel::class, 'class_id'); }
    public function lecturer(): BelongsTo { return $this->belongsTo(Lecturer::class); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by'); }
    public function attendances(): HasMany { return $this->hasMany(Attendance::class, 'journal_id'); }
    public function materials(): HasMany { return $this->hasMany(LectureMaterial::class, 'journal_id'); }
}
