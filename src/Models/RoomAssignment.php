<?php

namespace ConferenceTools\Registration\Models;

use ConferenceTools\Registration\Models\Concerns\HasRegistrationTable;
use ConferenceTools\Registration\Services\RoomAssigner;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One occupant (a registrant or a non-attending guest) housed in one room.
 * The first pass ({@see RoomAssigner})
 * creates these in bulk; the admin room-assignments console moves and
 * removes them freely — the questions never carry quite enough information
 * to finish the job.
 */
class RoomAssignment extends Model
{
    use HasFactory, HasRegistrationTable;

    protected $fillable = ['room_id', 'assignable_type', 'assignable_id'];

    /** The room this assignment places its occupant in. */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /** The housed occupant — a host user or a non-attending Guest. */
    public function assignable(): MorphTo
    {
        return $this->morphTo();
    }
}
