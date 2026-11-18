<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class MeetingMinutes extends Model
{
    protected $table = 'meeting_minutes';
    protected $fillable = [
        'created_by','event_id','reviewer_id','signer_id','notulis_id','chairman_id','number','title','meeting_date',
        'location','identity','participants','agenda','proceedings','decisions','follow_ups',
        'closing','status','reviewed_at','signed_at','notulis_signed_at','revision_note','verification_token',
    ];
    protected $casts = [
        'meeting_date' => 'date', 'participants' => 'array', 'follow_ups' => 'array',
        'reviewed_at' => 'datetime', 'signed_at' => 'datetime', 'notulis_signed_at' => 'datetime',
    ];
    protected static function booted(): void
    {
        static::creating(fn (self $minutes) => $minutes->verification_token = $minutes->verification_token ?: Str::random(32));
    }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewer_id'); }
    public function signer(): BelongsTo { return $this->belongsTo(User::class, 'signer_id'); }
    public function notulis(): BelongsTo { return $this->belongsTo(User::class, 'notulis_id'); }
    public function chairman(): BelongsTo { return $this->belongsTo(User::class, 'chairman_id'); }
    public function event(): BelongsTo { return $this->belongsTo(Event::class); }

    public function generateNumber(): string
    {
        $year = $this->meeting_date->year;
        $count = self::whereYear('meeting_date', $year)->whereNotNull('number')->count() + 1;
        $roman = ['', 'I','II','III','IV','V','VI','VII','VIII','IX','X','XI','XII'];
        return str_pad($count, 3, '0', STR_PAD_LEFT).'/NOT/STAI-YAB/'.$roman[$this->meeting_date->month].'/'.$year;
    }
}
