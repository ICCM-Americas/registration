<?php

namespace ConferenceTools\Registration\Models;

use ConferenceTools\Registration\Models\Concerns\HasRegistrationTable;
use ConferenceTools\Registration\Support\DiscountFormula;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A discount code a registrant may enter against a "discount_code" question. The
 * code is matched case-insensitively, ignoring surrounding whitespace; its formula
 * (see {@see DiscountFormula}) adjusts the registrant's — or group's — final price.
 */
class DiscountCode extends Model
{
    use HasFactory, HasRegistrationTable;

    protected $fillable = ['code', 'formula', 'description', 'enabled'];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    /** Apply this code's formula to a subtotal (rounded to 2dp, never below 0). */
    public function apply(float $subtotal): float
    {
        return (new DiscountFormula((string) $this->formula))->apply($subtotal);
    }

    /**
     * The enabled code matching the entered value, case-insensitively and ignoring
     * surrounding whitespace. Null when the value is blank or matches nothing.
     */
    public static function lookup(?string $code): ?self
    {
        $code = trim((string) $code);
        if ($code === '') {
            return null;
        }

        return self::where('enabled', true)
            ->whereRaw('lower(code) = ?', [mb_strtolower($code)])
            ->first();
    }
}
