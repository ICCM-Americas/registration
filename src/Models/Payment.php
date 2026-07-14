<?php

namespace ConferenceTools\Registration\Models;

use ConferenceTools\Registration\Models\Concerns\HasRegistrationTable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A registrant's manually-recorded payment state, kept by the admin Payments
 * console: whether they've paid, how much, and any free-text notes about the
 * payment. One row per registrant — see the owning migration's doc-comment
 * for why this is never shared across a group or a guest.
 */
class Payment extends Model
{
    use HasFactory, HasRegistrationTable;

    protected $fillable = ['user_id', 'notes'];

    protected $casts = [
        'is_paid' => 'boolean',
        'amount' => 'decimal:2',
    ];

    /** The registrant this payment record belongs to. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(config('registration.user_model'));
    }
}
