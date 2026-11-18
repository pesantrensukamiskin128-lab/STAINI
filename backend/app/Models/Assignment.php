<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Assignment extends Model
{
    protected $fillable = ['class_id', 'journal_id', 'title', 'description', 'instructions', 'attachment_path', 'attachment_url', 'attachment_type', 'type', 'due_date', 'max_score', 'is_published', 'allow_late'];
    protected $casts = ['due_date' => 'datetime', 'is_published' => 'boolean', 'allow_late' => 'boolean'];
    public function class_(): BelongsTo { return $this->belongsTo(ClassModel::class, 'class_id'); }
    public function submissions(): HasMany { return $this->hasMany(AssignmentSubmission::class); }
    public function subCpmks(): BelongsToMany
    {
        return $this->belongsToMany(RpkpsSubCpmk::class, 'assignment_sub_cpmk', 'assignment_id', 'rpkps_sub_cpmk_id');
    }
}
