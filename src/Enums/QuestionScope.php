<?php

namespace ConferenceTools\Registration\Enums;

use ConferenceTools\Registration\Models\GroupInvite;
use ConferenceTools\Registration\Models\Guest;

/**
 * Which registration entity a section's questions are answered against.
 * Participant-scoped questions (name, passport, gender, accommodation…) are
 * answered per registrant (host user); group-scoped questions (organization,
 * billing address…) are answered once per group; guest-scoped questions
 * (name, sex…) are answered once per non-attending guest a registrant adds
 * (see {@see Guest}); group-member-scoped
 * questions (name, email) are answered once per invitee a group leader adds,
 * purely to compose that invitee's invite email (see
 * {@see GroupInvite}).
 */
enum QuestionScope: string
{
    case Participant = 'participant';
    case Group = 'group';
    case Guest = 'guest';
    case GroupMember = 'group_member';

    /** The case values, for validation rules. */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
