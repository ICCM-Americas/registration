<?php

namespace ConferenceTools\Registration\Models;

use ConferenceTools\Registration\Models\Concerns\HasRegistrationTable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One registrant's (or opted-in adult guest's) placement into a prayer-pals
 * group. An admin places and moves these freely on the Prayer Pals console —
 * there is no automated grouping to seed them.
 */
class PrayerPalsAssignment extends Model
{
    use HasFactory, HasRegistrationTable;

    protected $fillable = ['prayer_pals_group_id', 'assignable_type', 'assignable_id'];

    /** The Prayer Pals group this member is assigned to. */
    public function group(): BelongsTo
    {
        return $this->belongsTo(PrayerPalsGroup::class, 'prayer_pals_group_id');
    }

    /** The grouped occupant — a host user or an opted-in adult Guest. */
    public function assignable(): MorphTo
    {
        return $this->morphTo();
    }
}
