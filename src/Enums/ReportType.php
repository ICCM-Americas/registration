<?php

namespace ConferenceTools\Registration\Enums;

/**
 * What an admin-defined report's rows are: registrants (each optionally
 * followed by their non-attending guests, who share the registrant's fate),
 * or individuals (registrants and guests as separate entities, each kept or
 * dropped on its own answers). Fixed once the report is created.
 */
enum ReportType: string
{
    case Registrant = 'registrant';
    case Individual = 'individual';

    /** The case values, for validation rules. */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** The type's translated name. */
    public function label(): string
    {
        return __('registration::admin.report_type_'.$this->value);
    }

    /** The type's translated one-line explanation, for the create form. */
    public function description(): string
    {
        return __('registration::admin.report_type_'.$this->value.'_description');
    }
}
