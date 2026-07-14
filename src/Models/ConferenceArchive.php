<?php

namespace ConferenceTools\Registration\Models;

use ConferenceTools\Registration\Models\Concerns\HasRegistrationTable;
use ConferenceTools\Registration\Services\RegistrationArchiver;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The single retained snapshot of an outgoing conference's registration
 * data, written by {@see RegistrationArchiver}.
 * Only one row is ever kept.
 */
class ConferenceArchive extends Model
{
    use HasFactory, HasRegistrationTable;

    protected $fillable = ['conference_name', 'conference_year', 'archived_at', 'data'];

    protected $casts = [
        'archived_at' => 'datetime',
        'data' => 'array',
    ];
}
