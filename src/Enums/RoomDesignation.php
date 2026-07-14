<?php

namespace ConferenceTools\Registration\Enums;

/**
 * Who a wing/floor combination of rooms is reserved for. Every room in one
 * wing/floor combo carries the same designation (enforced by the rooms admin
 * console, which designates whole combos rather than single rooms).
 */
enum RoomDesignation: string
{
    case Men = 'men';
    case Women = 'women';
    case Couples = 'couples';

    /** Backing values, e.g. for validation rules or migration column definitions. */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** The translated label shown on the room console. */
    public function label(): string
    {
        return match ($this) {
            self::Men => __('registration::admin.room_designation_men'),
            self::Women => __('registration::admin.room_designation_women'),
            self::Couples => __('registration::admin.room_designation_couples'),
        };
    }

    /** The designation whose rooms house single registrants of this gender. */
    public static function forGender(Gender $gender): self
    {
        return match ($gender) {
            Gender::Male => self::Men,
            Gender::Female => self::Women,
        };
    }
}
