<?php

namespace ConferenceTools\Registration\Enums;

/**
 * The physical badge/business-card stock the printable badge sheet can be
 * laid out for. Each Avery product prints its cards edge-to-edge with no
 * internal gutter, so the sheet margins below are simply the page size minus
 * the card grid, split evenly — the same arrangement Avery's own 5371
 * template uses (confirmed: 0.75in side / 0.5in top-bottom margins for a
 * 2x3.5in card, 2 across, 5 down, on US Letter).
 */
enum BadgeSheetSize: string
{
    case Avery5392 = 'avery_5392';
    case AveryL4728 = 'avery_l4728';
    case Avery5371 = 'avery_5371';
    case AveryC32011 = 'avery_c32011';

    /** Backing values, e.g. for validation rules. */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** The translated label shown in the size picker. */
    public function label(): string
    {
        return match ($this) {
            self::Avery5392 => __('registration::admin.badge_size_avery_5392'),
            self::AveryL4728 => __('registration::admin.badge_size_avery_l4728'),
            self::Avery5371 => __('registration::admin.badge_size_avery_5371'),
            self::AveryC32011 => __('registration::admin.badge_size_avery_c32011'),
        };
    }

    /** The paper size the sheet prints on. */
    public function pageSize(): string
    {
        return match ($this) {
            self::Avery5392, self::Avery5371 => 'letter',
            self::AveryL4728, self::AveryC32011 => 'a4',
        };
    }

    /** Card dimensions in millimeters: [width, height]. */
    public function cardSizeMm(): array
    {
        return match ($this) {
            self::Avery5392 => [101.6, 76.2],
            self::AveryL4728 => [90.0, 60.0],
            self::Avery5371 => [88.9, 50.8],
            self::AveryC32011 => [85.0, 54.0],
        };
    }

    /** Sheet grid: [columns, rows]. */
    public function grid(): array
    {
        return match ($this) {
            self::Avery5392 => [2, 3],
            self::AveryL4728 => [2, 4],
            self::Avery5371, self::AveryC32011 => [2, 5],
        };
    }

    /**
     * Outer sheet margins in millimeters, centering the card grid on the page
     * exactly as the Avery stock is die-cut: [horizontal, vertical]. The PDF
     * export places each card at these absolute coordinates, so no layout
     * engine can drift them.
     */
    public function marginsMm(): array
    {
        [$pageWidth, $pageHeight] = $this->pageSize() === 'letter' ? [215.9, 279.4] : [210.0, 297.0];
        [$cardWidth, $cardHeight] = $this->cardSizeMm();
        [$columns, $rows] = $this->grid();

        return [
            ($pageWidth - $columns * $cardWidth) / 2,
            ($pageHeight - $rows * $cardHeight) / 2,
        ];
    }

    /** The size to preselect: the badge-shaped option for the printer's locale, US Letter or A4. */
    public static function default(bool $isUsLocale): self
    {
        return $isUsLocale ? self::Avery5392 : self::AveryL4728;
    }
}
