<?php

namespace ConferenceTools\Registration\Models;

use ConferenceTools\Registration\Enums\Gender;
use ConferenceTools\Registration\Models\Concerns\HasRegistrationTable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A small (typically 3-4 person), single-sex prayer-pals grouping. An admin
 * creates and deletes these and drags registrants into them freely on the
 * Prayer Pals console — there is no automated grouping. Its displayed label
 * (a number or letter, per the admin-chosen {@see labelStyle()}) is derived
 * entirely from "position", the order the group was created in within its
 * sex, never picked by the admin directly.
 */
class PrayerPalsGroup extends Model
{
    use HasFactory, HasRegistrationTable;

    /** The runtime setting (see Setting) choosing how labels are displayed. */
    public const LABEL_STYLE_SETTING = 'prayer_pals_label_style';

    public const LABEL_STYLE_NUMBER = 'number';

    public const LABEL_STYLE_LETTER = 'letter';

    protected $fillable = ['sex', 'position'];

    protected $casts = [
        'sex' => Gender::class,
        'position' => 'integer',
    ];

    /** Who belongs to this group. */
    public function assignments(): HasMany
    {
        return $this->hasMany(PrayerPalsAssignment::class);
    }

    /** The current admin-chosen label style, numbered by default. */
    public static function labelStyle(): string
    {
        return Setting::get(self::LABEL_STYLE_SETTING) ?? self::LABEL_STYLE_NUMBER;
    }

    /** This group's displayed label — "1", "2", … or "A", "B", …, per the label style. */
    public function label(): string
    {
        return self::labelStyle() === self::LABEL_STYLE_LETTER
            ? self::positionToLetters($this->position)
            : (string) $this->position;
    }

    /** 1 => "A", 2 => "B", …, 26 => "Z", 27 => "AA" — spreadsheet-column style. */
    private static function positionToLetters(int $position): string
    {
        $letters = '';
        while ($position > 0) {
            $position--;
            $letters = chr(65 + ($position % 26)).$letters;
            $position = intdiv($position, 26);
        }

        return $letters;
    }
}
