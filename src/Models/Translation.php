<?php

namespace ConferenceTools\Registration\Models;

use ConferenceTools\Registration\Models\Concerns\HasRegistrationTable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One admin-provided translation: the value of one field of one translatable
 * entity (landing-page step, section, question, or option) in one locale. The
 * entity's own column keeps the base-language text; see TranslatesFields for
 * how the locale is resolved at render time.
 */
class Translation extends Model
{
    use HasFactory, HasRegistrationTable;

    protected $fillable = ['field', 'locale', 'value'];

    /** The step / section / question / option this translation belongs to. */
    public function translatable(): MorphTo
    {
        return $this->morphTo();
    }
}
