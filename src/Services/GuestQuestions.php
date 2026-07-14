<?php

namespace ConferenceTools\Registration\Services;

use ConferenceTools\Registration\Enums\Gender;
use ConferenceTools\Registration\Enums\GuestType;
use ConferenceTools\Registration\Http\Controllers\Admin\LogisticsController;
use ConferenceTools\Registration\Models\Guest;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\Setting;
use Illuminate\Database\Eloquent\Model;

/**
 * Which configured questions feed the non-attending guest feature: the
 * Guest-scope questions nominated to mean a guest's display name, their
 * report name (badges/room assignment/shuttle — separate from the
 * display name the same way a registrant's badge name is separate from their
 * first/last name), and gender/roommate/Prayer-Pals details. There is
 * no arrival/flight nomination — guests travel with their registrant, so
 * {@see ShuttlePlanner} reads the registrant's own arrival/flight answers for
 * them. Kept separate from {@see ReportQuestions} because Guest-scope
 * question keys are isolated from Participant-scope keys — an admin could
 * give a Guest-scope question the same key ("name") as a Participant-scope
 * one, so a guest's nominations can never be resolved through the
 * Participant-scope nominations.
 *
 * The Guest List hub itself is revealed by the fixed, seeded
 * {@see Question::GUEST_TRIGGER_KEY}
 * question (see RegistrationController), not a nomination — there is
 * nothing to configure here for it.
 */
class GuestQuestions
{
    use Concerns\ManagesNominatedQuestions;

    /** Which Guest-scope question is a guest's display name — used pre-commit on the Guest List hub, and as matching input (roommate normalization). */
    public const NAME_KEY = 'guest_name_key';

    /**
     * How a guest's name should appear on their printed badge — falls back to
     * the plain NAME_KEY answer while unnominated or unanswered, mirroring
     * {@see ReportQuestions::BADGE_NAME_KEY} but with a fallback, since a
     * guest's badge is optional (see MINORS_GET_BADGES) and shouldn't go
     * blank just because this separate formatting question hasn't been
     * configured yet. Operational consoles (room assignment, shuttle,
     * Prayer Pals) use {@see occupantFullName()} instead, so this stays
     * badges-only.
     */
    public const BADGE_NAME_KEY = 'guest_badge_name_key';

    /** Whether minor guests get a badge (some host locations require one for meal access) — defaults off. */
    public const MINORS_GET_BADGES = 'guest_minors_get_badges';

    /** A guest's gender, for the room-assignment first pass. */
    public const GENDER_KEY = 'guest_gender_key';

    /** Who the guest would like to room with (matched against the combined registrant+guest pool). */
    public const ROOMMATE_KEY = 'guest_roommate_key';

    /** Whether an adult guest opts in to Prayer Pals. Minors are never included, unconditionally. */
    public const PRAYER_PALS_OPT_IN_KEY = 'guest_prayer_pals_key';

    /** Answer values (comma-separated) meaning "yes, include me in Prayer Pals". */
    public const PRAYER_PALS_OPT_IN_VALUES = 'guest_prayer_pals_opt_in_values';

    /**
     * The Guest-scope nominations rendered generically by
     * {@see LogisticsController}
     * in its "Non-Attending Guest Report Questions" card, the same way
     * {@see ReportQuestions::QUESTION_KEYS} is — NAME_KEY sits alongside the
     * report-only nominations because it is likewise a Guest-scope question
     * pick, even though it also feeds the Guest List hub (see displayName())
     * rather than a report. There is no arrival/flight nomination: guests are
     * assumed to travel with their registrant, so the shuttle schedule reads
     * the registrant's own arrival/flight answers for every guest they bring
     * — see {@see ShuttlePlanner}.
     */
    public const REPORT_QUESTION_KEYS = [
        self::NAME_KEY,
        self::BADGE_NAME_KEY,
        self::GENDER_KEY,
        self::ROOMMATE_KEY,
        self::PRAYER_PALS_OPT_IN_KEY,
    ];

    /** Every question-nominating setting — used by update(), which is safe to call from any page's controller since it only writes keys actually present in the submitted values. */
    public const QUESTION_KEYS = self::REPORT_QUESTION_KEYS;

    /** The value-list settings feeding the consoles, and their defaults. */
    public const REPORT_VALUE_DEFAULTS = [
        self::PRAYER_PALS_OPT_IN_VALUES => 'Yes',
    ];

    /** Every value-list setting's default. */
    public const VALUE_DEFAULTS = self::REPORT_VALUE_DEFAULTS;

    /** Which question-nominating setting each report value-list setting qualifies. */
    public const REPORT_VALUE_QUESTION_KEYS = [
        self::PRAYER_PALS_OPT_IN_VALUES => self::PRAYER_PALS_OPT_IN_KEY,
    ];

    /** Every value-list-setting-to-question-setting pairing. */
    public const VALUE_QUESTION_KEYS = self::REPORT_VALUE_QUESTION_KEYS;

    /** Whether minor guests get a badge — defaults off. */
    public function minorsGetBadges(): bool
    {
        return (bool) Setting::get(self::MINORS_GET_BADGES);
    }

    /** Store whether minor guests get a badge. */
    public function updateMinorsGetBadges(bool $value): void
    {
        Setting::put(self::MINORS_GET_BADGES, $value ? '1' : null);
    }

    /** A committed guest's display name: the nominated Guest-scope question's answer. */
    public function displayName(Guest $guest): ?string
    {
        $key = $this->questionKey(self::NAME_KEY);

        return $key !== null ? $guest->registrationAnswers()->display($key) : null;
    }

    /**
     * How this guest's name should appear on reports: the nominated
     * BADGE_NAME_KEY answer, or the plain display name while that's
     * unnominated or unanswered — see BADGE_NAME_KEY's own doc-comment for
     * why this falls back instead of going blank like the registrant
     * equivalent does.
     */
    public function badgeName(Guest $guest): ?string
    {
        $key = $this->questionKey(self::BADGE_NAME_KEY);
        $value = $key !== null ? $guest->registrationAnswers()->display($key) : null;

        return $value !== null && $value !== '' ? $value : $this->displayName($guest);
    }

    /** A badge-formatted display name for either a real registrant or a Guest — used only by the Badges console. */
    public function occupantName(Model $occupant, ReportQuestions $questions): ?string
    {
        return $occupant instanceof Guest ? $this->badgeName($occupant) : $questions->badgeName($occupant);
    }

    /**
     * The occupant's operational name — first+last for a registrant, their
     * own display name for a guest — as opposed to {@see occupantName()}'s
     * badge-formatted name, which can be a nickname or omit a surname.
     * Used by consoles where confirming exactly who was placed matters more
     * than matching what prints on their badge (room assignments, shuttle
     * schedule, Prayer Pals).
     */
    public function occupantFullName(Model $occupant, ReportQuestions $questions): ?string
    {
        return $occupant instanceof Guest ? $this->displayName($occupant) : $questions->fullName($occupant);
    }

    /**
     * A guest's gender, leniently matched exactly like {@see ReportQuestions::gender()}:
     * the enum's own values ("m"/"f") or any answer starting with them. Null
     * when unanswered or unrecognized — the room-assignment first pass then
     * leaves them unassigned.
     */
    public function gender(Guest $guest): ?Gender
    {
        $key = $this->questionKey(self::GENDER_KEY);
        $value = $key !== null ? $guest->registrationAnswers()->value($key) : null;
        if (! is_string($value) || $value === '') {
            return null;
        }

        return Gender::tryFrom(mb_strtolower($value)) ?? Gender::tryFrom(mb_strtolower(mb_substr($value, 0, 1)));
    }

    /** Who the guest would like to room with — matched against the combined registrant+guest pool. */
    public function roommateName(Guest $guest): ?string
    {
        $key = $this->questionKey(self::ROOMMATE_KEY);

        return $key !== null ? $guest->registrationAnswers()->display($key) : null;
    }

    /**
     * A guest's own normalized name — the basis of room-assignment matching,
     * built from their display name the same way {@see Registrants::normalizedName()}
     * builds one from a registrant's first+last name.
     */
    public function normalizedName(Guest $guest): string
    {
        return Registrants::normalize((string) $this->displayName($guest));
    }

    /**
     * Whether this guest should be included in Prayer Pals. A minor guest is
     * NEVER included — unconditional, no override. An adult guest is
     * included only if they explicitly opted in via the nominated
     * Guest-scope question — unconfigured or unanswered means excluded,
     * since this is an opt-in, not an opt-out.
     */
    public function prayerPalsOptedIn(Guest $guest): bool
    {
        if ($guest->type === GuestType::Minor) {
            return false;
        }

        return $this->guestAnswerMatches($guest, self::PRAYER_PALS_OPT_IN_KEY, $this->valueList(self::PRAYER_PALS_OPT_IN_VALUES));
    }

    /** Whether any of a guest's own answer's raw value(s) is in the normalized list. */
    private function guestAnswerMatches(Guest $guest, string $setting, array $list): bool
    {
        $key = $this->questionKey($setting);
        $value = $key !== null ? $guest->registrationAnswers()->value($key) : null;

        return collect(is_array($value) ? $value : [$value])
            ->contains(fn ($v): bool => is_string($v) && in_array(mb_strtolower(trim($v)), $list, true));
    }
}
