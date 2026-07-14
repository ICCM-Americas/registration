<?php

namespace ConferenceTools\Registration\Models;

use ConferenceTools\Registration\Enums\BadgeElementType;
use ConferenceTools\Registration\Enums\BadgeSheetSize;
use ConferenceTools\Registration\Models\Concerns\HasRegistrationTable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One piece of admin-placed content on a badge card (badge name, logo,
 * conference name, organization, static text, or a static image), positioned
 * as a percentage of the card's own width/height so each {@see BadgeSheetSize}
 * can be laid out independently without any scale math.
 */
class BadgeLayoutElement extends Model
{
    use HasFactory, HasRegistrationTable;

    protected $fillable = [
        'sheet_size', 'type', 'x_pct', 'y_pct', 'width_pct',
        'font_size_pt', 'bold', 'italic', 'align', 'text', 'image_data', 'image_mime',
    ];

    protected $casts = [
        'sheet_size' => BadgeSheetSize::class,
        'type' => BadgeElementType::class,
        'x_pct' => 'float',
        'y_pct' => 'float',
        'width_pct' => 'float',
        'font_size_pt' => 'integer',
        'bold' => 'boolean',
        'italic' => 'boolean',
    ];

    /**
     * The starting layout offered on the designer page — a rough approximation
     * of the report's built-in default rendering (logo beside the conference
     * name, badge name centered, organization centered near the bottom), which
     * an admin can then freely rearrange.
     */
    public const DEFAULTS = [
        ['type' => BadgeElementType::Logo, 'x_pct' => 4, 'y_pct' => 4, 'width_pct' => 20, 'align' => 'left'],
        ['type' => BadgeElementType::ConferenceName, 'x_pct' => 26, 'y_pct' => 5, 'width_pct' => 70, 'align' => 'left', 'font_size_pt' => 12],
        ['type' => BadgeElementType::BadgeName, 'x_pct' => 0, 'y_pct' => 43, 'width_pct' => 100, 'align' => 'center', 'font_size_pt' => 26, 'bold' => true],
        ['type' => BadgeElementType::Organization, 'x_pct' => 0, 'y_pct' => 75, 'width_pct' => 100, 'align' => 'center', 'font_size_pt' => 11, 'italic' => true],
    ];

    /** Populates a size's layout with the defaults above, unless it already has any elements. */
    public static function seedDefaults(BadgeSheetSize $size): void
    {
        if (self::where('sheet_size', $size)->exists()) {
            return;
        }

        foreach (self::DEFAULTS as $row) {
            self::create($row + ['sheet_size' => $size]);
        }
    }
}
