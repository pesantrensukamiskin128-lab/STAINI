<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RpkpsAssessmentInstrument extends Model
{
    protected $fillable = [
        'rpkps_id', 'rpkps_assessment_id', 'sub_cpmk', 'instrument_name',
        'instrument_type', 'technique', 'cognitive_level', 'affective_level',
        'psychomotor_level', 'item_numbers', 'weight', 'timing', 'description', 'order',
    ];

    public function rpkps(): BelongsTo { return $this->belongsTo(Rpkps::class); }
    public function assessment(): BelongsTo { return $this->belongsTo(RpkpsAssessment::class, 'rpkps_assessment_id'); }
}
