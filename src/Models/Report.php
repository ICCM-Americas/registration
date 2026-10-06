<?php

namespace ConferenceTools\Registration\Models;

use ConferenceTools\Registration\Enums\ReportType;
use ConferenceTools\Registration\Models\Concerns\HasRegistrationTable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * An admin-defined report: a named listing of registrants (and optionally
 * their non-attending guests) whose columns are configured questions' answers
 * or built-in fields (see ReportColumn), and whose rows are filtered by a
 * visibility-style rule tree. Its type (see ReportType) decides whether that
 * rule keeps whole families or each registrant and guest on their own.
 */
class Report extends Model
{
    use HasFactory, HasRegistrationTable;

    protected $fillable = [
        'name', 'description', 'type', 'header', 'footer', 'include_adult_guests', 'include_minor_guests', 'position',
    ];

    protected $casts = [
        'type' => ReportType::class,
        'include_adult_guests' => 'boolean',
        'include_minor_guests' => 'boolean',
        'position' => 'integer',
    ];

    /** Model-level lifecycle wiring for a report and its dependents. */
    protected static function booted(): void
    {
        // The DB would cascade-delete the columns without firing their model
        // events, orphaning their per-row rule trees; delete them as models.
        // The report's own row rule tree is polymorphic (no DB cascade at all).
        static::deleting(function (Report $report) {
            $report->columns()->get()->each->delete();
            $report->conditionGroups()->get()->each->delete();
        });
    }

    /** The report's columns, in display order. */
    public function columns(): HasMany
    {
        return $this->hasMany(ReportColumn::class)->orderBy('position');
    }

    /**
     * The root of this report's row rule, if any. A report with no root group
     * lists every registrant (and every included guest).
     */
    public function conditionGroups(): MorphMany
    {
        return $this->morphMany(ConditionGroup::class, 'conditionable')->whereNull('parent_group_id');
    }
}
