<?php

namespace ConferenceTools\Registration\Models;

use ConferenceTools\Registration\Models\Concerns\HasRegistrationTable;
use ConferenceTools\Registration\Services\VariableInterpolator;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An admin-defined variable interpolated into registrant-facing texts (landing
 * page steps, question labels/help/placeholders, option labels/descriptions) by
 * writing "{name}" in the text. The name is a stable machine name; only the
 * value is edited afterwards, so existing tokens never dangle on a rename.
 */
class Variable extends Model
{
    use HasFactory, HasRegistrationTable;

    protected $fillable = ['name', 'value'];

    /** Keep the interpolator cache in step with variable changes. */
    protected static function booted(): void
    {
        // The interpolator memoizes the name => value map per request; a changed
        // variable must show up in texts rendered later in the same request
        // (e.g. tests that save and immediately re-render).
        $flush = fn () => app(VariableInterpolator::class)->flush();
        static::saved($flush);
        static::deleted($flush);
    }

    /**
     * The interpolation map.
     *
     * @return array<string, string>
     */
    public static function replacements(): array
    {
        return self::pluck('value', 'name')->all();
    }
}
