<?php

namespace ConferenceTools\Registration\Services;

use Carbon\CarbonImmutable;
use ConferenceTools\Registration\Models\Setting;

/**
 * The conference edition — the one place the conference's name and year are
 * recorded. Admin-authored texts reference them through the built-in
 * {conference_name} and {conference_year} variables (see VariableInterpolator),
 * so questions, landing-page steps and emails never repeat them.
 *
 * The week of the conference, the next edition's form is already being set up.
 * {@see startNext()} rolls the edition over — advancing the year and archiving
 * the outgoing name and year — so the "registration has closed" page keeps
 * naming the conference whose registration actually closed:
 * {@see closedName()}/{@see closedYear()} return the archived edition whenever
 * the rollover happened after the window closed, and the current edition
 * otherwise (i.e. once the new edition's own window has closed).
 */
class ConferenceEdition
{
    public const NAME = 'conference_name';

    public const YEAR = 'conference_year';

    public const PREVIOUS_NAME = 'previous_conference_name';

    public const PREVIOUS_YEAR = 'previous_conference_year';

    public const ROLLED_OVER_AT = 'conference_rolled_over_at';

    public function __construct(private RegistrationStatus $status) {}

    /** The current conference's name, or null while unset. */
    public function name(): ?string
    {
        return Setting::get(self::NAME);
    }

    /** The current conference's year, or null while unset. */
    public function year(): ?string
    {
        return Setting::get(self::YEAR);
    }

    /** Storing an empty name or year clears it. */
    public function update(?string $name, ?string $year): void
    {
        Setting::put(self::NAME, filled($name) ? $name : null);
        Setting::put(self::YEAR, filled($year) ? $year : null);
    }

    /**
     * Begin setting up the next conference: archive the current edition for
     * the closed page and advance the year. The name carries over — edit it
     * afterwards if it changes from year to year.
     */
    public function startNext(): void
    {
        Setting::put(self::PREVIOUS_NAME, $this->name());
        Setting::put(self::PREVIOUS_YEAR, $this->year());
        Setting::put(self::ROLLED_OVER_AT, now()->toDateTimeString());

        $year = $this->year();
        if ($year !== null && ctype_digit($year)) {
            Setting::put(self::YEAR, (string) ((int) $year + 1));
        }
    }

    /** The name of the conference the closed page refers to (class docblock). */
    public function closedName(): ?string
    {
        return $this->closedEditionIsArchived() ? Setting::get(self::PREVIOUS_NAME) : $this->name();
    }

    /** The year of the conference the closed page refers to (class docblock). */
    public function closedYear(): ?string
    {
        return $this->closedEditionIsArchived() ? Setting::get(self::PREVIOUS_YEAR) : $this->year();
    }

    /**
     * Whether the archived edition is the one whose registration closed: the
     * rollover happened after the window closed, so the current edition is the
     * next conference (still being set up) while the closed page must keep
     * referring to the outgoing one.
     */
    private function closedEditionIsArchived(): bool
    {
        $rolledOverAt = Setting::get(self::ROLLED_OVER_AT);
        $closesAt = $this->status->closesAt();

        return $rolledOverAt !== null
            && $closesAt !== null
            && CarbonImmutable::parse($rolledOverAt)->greaterThan($closesAt);
    }
}
