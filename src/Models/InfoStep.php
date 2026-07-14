<?php

namespace ConferenceTools\Registration\Models;

use ConferenceTools\Registration\Models\Concerns\HasRegistrationTable;
use ConferenceTools\Registration\Models\Concerns\TranslatesFields;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One numbered step on the public registration landing page. Steps are shown in
 * position order and numbered from 1 as rendered, so hiding or removing a step
 * renumbers the rest automatically. Texts may contain "{name}" variable tokens
 * (see {@see Variable} and VariableInterpolator) and are translatable per locale
 * (see {@see TranslatesFields}).
 */
class InfoStep extends Model
{
    use HasFactory, HasRegistrationTable, TranslatesFields;

    protected $fillable = ['heading', 'body', 'position', 'enabled'];

    protected $casts = [
        'position' => 'integer',
        'enabled' => 'boolean',
    ];

    /** The fields admins may translate. */
    public function translatableFields(): array
    {
        return ['heading', 'body'];
    }

    /** The enabled steps, in display order. */
    public function scopeShown($query)
    {
        return $query->where('enabled', true)->orderBy('position');
    }
}
