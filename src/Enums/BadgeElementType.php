<?php

namespace ConferenceTools\Registration\Enums;

/**
 * The kinds of content an admin can place on a badge card via the badge
 * layout designer. BadgeName/Logo/ConferenceName/Organization are singular
 * (at most one per {@see BadgeSheetSize}); StaticText/StaticImage allow up to
 * two each, so an admin can add supplementary content without the system
 * becoming infinitely configurable.
 */
enum BadgeElementType: string
{
    case BadgeName = 'badge_name';
    case Logo = 'logo';
    case ConferenceName = 'conference_name';
    case Organization = 'organization';
    case StaticText = 'static_text';
    case StaticImage = 'static_image';
    case PrayerPalsGroup = 'prayer_pals_group';

    /** Backing values, e.g. for validation rules. */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** The translated label shown in the layout editor. */
    public function label(): string
    {
        return match ($this) {
            self::BadgeName => __('registration::admin.badge_element_badge_name'),
            self::Logo => __('registration::admin.badge_element_logo'),
            self::ConferenceName => __('registration::admin.badge_element_conference_name'),
            self::Organization => __('registration::admin.badge_element_organization'),
            self::StaticText => __('registration::admin.badge_element_static_text'),
            self::StaticImage => __('registration::admin.badge_element_static_image'),
            self::PrayerPalsGroup => __('registration::admin.badge_element_prayer_pals_group'),
        };
    }

    /** How many of this type may exist per sheet size. */
    public function maxPerSize(): int
    {
        return match ($this) {
            self::StaticText, self::StaticImage => 2,
            default => 1,
        };
    }

    /** Whether this element renders text (and so has font size/bold/italic). */
    public function isTextual(): bool
    {
        return match ($this) {
            self::BadgeName, self::ConferenceName, self::Organization, self::StaticText, self::PrayerPalsGroup => true,
            self::Logo, self::StaticImage => false,
        };
    }

    /** Whether this element renders an image. */
    public function isImage(): bool
    {
        return match ($this) {
            self::Logo, self::StaticImage => true,
            default => false,
        };
    }

    /** The starting font size (in points) offered when the element is added. */
    public function defaultFontSizePt(): ?int
    {
        return match ($this) {
            self::BadgeName => 26,
            self::ConferenceName => 12,
            self::Organization => 11,
            self::StaticText => 12,
            self::PrayerPalsGroup => 14,
            self::Logo, self::StaticImage => null,
        };
    }
}
