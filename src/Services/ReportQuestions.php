<?php

namespace ConferenceTools\Registration\Services;

use ConferenceTools\Registration\Enums\Gender;
use ConferenceTools\Registration\Support\FlightTimes;
use Illuminate\Database\Eloquent\Model;

/**
 * Which configured questions feed the conference consoles (badges, room
 * assignments, shuttle runs) and the built-in report fields. The questions
 * are admin-built, so their keys cannot be hard-coded; an admin nominates each
 * one on the Logistics console, the same way {@see GuestQuestions} nominates
 * the Guest-scope equivalents for non-attending guests.
 *
 * The answer-reading helpers all take a registrant (host user) and interpret
 * the nominated question's answer, staying deliberately lenient — admin-built
 * questions vary.
 */
class ReportQuestions
{
    use Concerns\ManagesNominatedQuestions;

    /** How the registrant's name should appear on their name badge. */
    public const BADGE_NAME_KEY = 'report_badge_name_key';

    /** The registrant's first name, for report sorting and roommate matching. */
    public const FIRSTNAME_KEY = 'report_firstname_key';

    /** The registrant's last name, for report sorting and roommate matching. */
    public const LASTNAME_KEY = 'report_lastname_key';

    /** The registrant's organization (participant answer, group answer, or the group name). */
    public const ORGANIZATION_KEY = 'report_organization_key';

    /** The registrant's gender, for the room-assignment first pass. */
    public const GENDER_KEY = 'report_gender_key';

    /**
     * Who the registrant would like to room with — also how couples are
     * inferred (mostly), when the named roommate has a different gender.
     */
    public const ROOMMATE_KEY = 'report_roommate_key';

    /** The registrant's day of arrival, so organizers know whose rooms must be ready when. */
    public const ARRIVAL_KEY = 'report_arrival_key';

    /**
     * The arriving flight's landing time (local) — answered only by air
     * arrivals. May nominate the same free-text travel-plans question as
     * {@see FLIGHT_DEPARTURE_KEY}; see {@see flightTime()} for how one
     * answer then covers both flights.
     */
    public const FLIGHT_ARRIVAL_KEY = 'report_flight_arrival_key';

    /** The return flight's take-off time (local) — answered only by air departures. */
    public const FLIGHT_DEPARTURE_KEY = 'report_flight_departure_key';

    /** The question-nominating settings, in the order the Logistics console shows them. */
    public const QUESTION_KEYS = [
        self::BADGE_NAME_KEY,
        self::FIRSTNAME_KEY,
        self::LASTNAME_KEY,
        self::ORGANIZATION_KEY,
        self::GENDER_KEY,
        self::ROOMMATE_KEY,
        self::ARRIVAL_KEY,
        self::FLIGHT_ARRIVAL_KEY,
        self::FLIGHT_DEPARTURE_KEY,
    ];

    /** The value-list settings and their defaults, applied while unset — none remain. */
    public const VALUE_DEFAULTS = [];

    /**
     * Which question-nominating setting each value-list setting qualifies —
     * none remain since the hard-coded reports (whose opt-out matching these
     * powered) became admin-defined reports with their own rules.
     */
    public const VALUE_QUESTION_KEYS = [];

    // -- Answer interpretation ------------------------------------------------

    /**
     * The badge name: the nominated question's answer. There is no fallback —
     * registration is refused while the badge-name question is unnominated
     * (see {@see RegistrationStatus}),
     * so every registrant reached through the live flow has one.
     */
    public function badgeName(Model $registrant): ?string
    {
        return $this->answer($registrant, self::BADGE_NAME_KEY);
    }

    /** The registrant's first name: the nominated question's answer. */
    public function firstName(Model $registrant): ?string
    {
        return $this->answer($registrant, self::FIRSTNAME_KEY);
    }

    /** The registrant's last name: the nominated question's answer. */
    public function lastName(Model $registrant): ?string
    {
        return $this->answer($registrant, self::LASTNAME_KEY);
    }

    /**
     * The registrant's first and last name joined for display — the
     * operational name consoles like room assignments use to make sure
     * they're placing/moving the right person, as opposed to
     * {@see badgeName()}'s formatted-for-printing name.
     */
    public function fullName(Model $registrant): ?string
    {
        $name = trim(($this->firstName($registrant) ?? '').' '.($this->lastName($registrant) ?? ''));

        return $name !== '' ? $name : null;
    }

    /**
     * The organization to show for a registrant: their own answer, their
     * group's answer to the same question, or the group (organization) name.
     */
    public function organization(Model $registrant): ?string
    {
        if ($answer = $this->answer($registrant, self::ORGANIZATION_KEY)) {
            return $answer;
        }

        $group = $registrant->group;
        if ($group === null) {
            return null;
        }

        $key = $this->questionKey(self::ORGANIZATION_KEY);

        return ($key !== null ? $group->registrationAnswers()->display($key) : null) ?: $group->name;
    }

    /**
     * The registrant's gender, leniently matched: the enum's own values ("m"/
     * "f") or any answer starting with them ("male", "Female", …). Null when
     * unanswered or unrecognized — the first pass then leaves them unassigned.
     */
    public function gender(Model $registrant): ?Gender
    {
        $value = $this->answer($registrant, self::GENDER_KEY, raw: true);
        if (! is_string($value) || $value === '') {
            return null;
        }

        return Gender::tryFrom(mb_strtolower($value)) ?? Gender::tryFrom(mb_strtolower(mb_substr($value, 0, 1)));
    }

    /** The registrant's roommate-preference answer. */
    public function roommateName(Model $registrant): ?string
    {
        return $this->answer($registrant, self::ROOMMATE_KEY);
    }

    /** The arrival-day answer's raw value — the grouping key for arrival lists. */
    public function arrivalDay(Model $registrant): ?string
    {
        return $this->rawString($registrant, self::ARRIVAL_KEY);
    }

    /** The arriving flight's landing time, extracted from the nominated answer; null for non-air arrivals. */
    public function flightArrivalTime(Model $registrant): ?string
    {
        return $this->flightTime($registrant, self::FLIGHT_ARRIVAL_KEY, earliest: true);
    }

    /** The return flight's take-off time, extracted from the nominated answer; null for non-air departures. */
    public function flightDepartureTime(Model $registrant): ?string
    {
        return $this->flightTime($registrant, self::FLIGHT_DEPARTURE_KEY, earliest: false);
    }

    // -- Helpers ---------------------------------------------------------------

    /**
     * A flight question's answer as an "HH:MM" clock time. Answers are free
     * text ("AA1234 landing 10:35 AM"), so the times are extracted from
     * around the flight numbers ({@see FlightTimes}). Both flight settings
     * may nominate the same travel-plans question — the earliest extracted
     * time is then the arrival and the latest the departure, and an answer
     * naming fewer than two times is returned unparsed, so the shuttle
     * planner surfaces it for hand scheduling instead of guessing which
     * flight the one time belongs to.
     */
    private function flightTime(Model $registrant, string $setting, bool $earliest): ?string
    {
        $raw = $this->rawString($registrant, $setting);
        if ($raw === null) {
            return null;
        }

        $times = FlightTimes::extract($raw);
        $shared = $this->questionKey(self::FLIGHT_ARRIVAL_KEY) === $this->questionKey(self::FLIGHT_DEPARTURE_KEY);
        if (count($times) < ($shared ? 2 : 1)) {
            return $raw;
        }

        return $earliest ? $times[0] : $times[count($times) - 1];
    }

    /** A single-value answer's raw value, or null when unanswered/not a string. */
    private function rawString(Model $registrant, string $setting): ?string
    {
        $value = $this->answer($registrant, $setting, raw: true);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** The nominated question's answer for a registrant (display or raw form). */
    private function answer(Model $registrant, string $setting, bool $raw = false): mixed
    {
        $key = $this->questionKey($setting);
        if ($key === null) {
            return null;
        }

        $answers = $registrant->registrationAnswers();

        return $raw ? $answers->value($key) : ($answers->display($key) ?: null);
    }
}
