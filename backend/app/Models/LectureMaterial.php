<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LectureMaterial extends Model
{
    protected $fillable = ['class_id', 'journal_id', 'title', 'description', 'material_type', 'file_path', 'file_url', 'file_type', 'is_published', 'available_at', 'created_by'];
    protected $casts = ['is_published' => 'boolean', 'available_at' => 'datetime'];
    public function class_(): BelongsTo { return $this->belongsTo(ClassModel::class, 'class_id'); }
    public function journal(): BelongsTo { return $this->belongsTo(LectureJournal::class, 'journal_id'); }
}
