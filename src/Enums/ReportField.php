<?php

namespace ConferenceTools\Registration\Enums;

/**
 * The built-in (non-question) fields an admin-defined report column can show:
 * facts that aren't a single question's answer — the registrant's account
 * email, the row's entry type, or the nominated badge-name/organization
 * derivations shared with the consoles.
 */
enum ReportField: string
{
    case Email = 'email';
    case EntryType = 'entry_type';
    case BadgeName = 'badge_name';
    case Organization = 'organization';

    /** The case values, for validation rules. */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** The field's translated default column heading. */
    public function label(): string
    {
        return __('registration::admin.report_builtin_'.$this->value);
    }
}
