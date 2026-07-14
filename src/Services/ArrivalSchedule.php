<?php

namespace ConferenceTools\Registration\Services;

use ConferenceTools\Registration\Models\Question;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Who arrives on which day — read from the nominated arrival-day question —
 * so organizers know whose rooms must be ready when. Distinct from the
 * airport shuttle schedule ({@see ShuttlePlanner}): every registrant arrives
 * on some day, but only some of them arrive by air.
 */
class ArrivalSchedule
{
    public function __construct(
        private Registrants $registrants,
        private ReportQuestions $questions,
    ) {}

    /**
     * Registrants grouped by their arrival-day answer's raw value, in day
     * order ({@see dayOrder()}). Registrants without an answer are omitted —
     * {@see withoutArrivalDay()} surfaces them separately.
     *
     * @return Collection<string, Collection<int, Model>>
     */
    public function arrivalsByDay(): Collection
    {
        return $this->byDay(fn ($user): ?string => $this->questions->arrivalDay($user), ReportQuestions::ARRIVAL_KEY);
    }

    /** Registrants who never answered the arrival-day question. */
    public function withoutArrivalDay(): Collection
    {
        return $this->registrants->all()
            ->filter(fn ($user): bool => $this->questions->arrivalDay($user) === null)
            ->values();
    }

    /** The label to print for an arrival-day value: its option label, or the value itself. */
    public function dayLabel(string $day): string
    {
        return $this->optionLabel(ReportQuestions::ARRIVAL_KEY, $day);
    }

    /**
     * Registrants grouped and ordered by a day-question answer — shared with
     * the shuttle planner, which groups by the same day questions.
     *
     * @return Collection<string, Collection<int, Model>>
     */
    public function byDay(callable $dayOf, string $setting): Collection
    {
        return $this->byDayFor($this->registrants->all(), $dayOf, $setting);
    }

    /**
     * As {@see byDay()}, but over any given pool of occupants rather than
     * registrants alone — the shuttle planner's non-attending-guest pool uses
     * this directly, so {@see byDay()}/{@see arrivalsByDay()} (and the
     * Arrival List report they serve) stay registrant-only and unaffected.
     *
     * @return Collection<string, Collection<int, Model>>
     */
    public function byDayFor(Collection $pool, callable $dayOf, string $setting): Collection
    {
        $order = $this->dayOrder($pool, $dayOf, $setting);

        // toBase: grouped by day value, so Eloquent's by-model-key semantics
        // (notably except()) must not apply.
        return $pool
            ->toBase()
            ->groupBy(fn ($occupant): string => (string) $dayOf($occupant))
            ->except([''])
            ->sortBy(fn (Collection $group, string $day) => $order[$day] ?? PHP_INT_MAX);
    }

    /**
     * Day sort ranks. A choice question's options already carry the intended
     * order; otherwise the values are compared as dates when they all parse,
     * and as plain strings when they don't.
     *
     * @return array<string, int>
     */
    private function dayOrder(Collection $pool, callable $dayOf, string $setting): array
    {
        $question = $this->dayQuestion($setting);

        if ($question !== null && $question->usesOptions()) {
            return $question->options->pluck('value')->flip()->all();
        }

        $days = $pool
            ->map(fn ($occupant): ?string => $dayOf($occupant))
            ->filter()
            ->unique()
            ->values();

        $sorted = $days->every(fn (string $day): bool => strtotime($day) !== false)
            ? $days->sortBy(fn (string $day): int => strtotime($day))
            : $days->sort();

        return $sorted->values()->flip()->all();
    }

    /** The day option's label on the nominated question, or the raw value. */
    private function optionLabel(string $setting, string $day): string
    {
        return $this->dayQuestion($setting)?->options->firstWhere('value', $day)?->label ?? $day;
    }

    /** The nominated day question with its options, or null while unset. */
    private function dayQuestion(string $setting): ?Question
    {
        $key = $this->questions->questionKey($setting);

        return $key !== null ? Question::with('options')->where('key', $key)->first() : null;
    }
}
