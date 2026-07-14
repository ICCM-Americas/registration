<?php

namespace ConferenceTools\Registration\Services;

use ConferenceTools\Registration\Http\Controllers\Admin\PrayerPalsController;
use ConferenceTools\Registration\Models\Guest;
use ConferenceTools\Registration\Models\PrayerPalsAssignment;
use ConferenceTools\Registration\Models\RoomAssignment;
use ConferenceTools\Registration\Models\Setting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The admin dashboard's Summary card: attendee/guest headcounts and the
 * at-a-glance state of the logistics consoles (special needs, arrivals,
 * shuttle runs, room assignments, Prayer Pals). Each figure is computed the
 * same way its own console does, so the dashboard number always agrees with
 * what an admin finds after drilling into it.
 */
class DashboardSummary
{
    public function __construct(
        private Registrants $registrants,
        private GuestQuestions $guestQuestions,
        private ArrivalSchedule $arrivals,
        private ShuttlePlanner $shuttles,
    ) {}

    /** Completed registrants. */
    public function attendeesCount(): int
    {
        return $this->registrants->all()->count();
    }

    /** Every non-attending guest a registrant has brought, adult and minor alike. */
    public function guestsCount(): int
    {
        return $this->registrants->all()->flatMap(fn (Model $u) => $u->guests)->count();
    }

    /**
     * Registrants who answered the special-needs question — a bare setting
     * rather than a {@see ReportQuestions} nomination (see
     * DefaultReportsSeeder) — so it is read directly here instead of through
     * that service. Zero while the question is unnominated, the same
     * no-fallback rule the nominated-question services follow.
     */
    public function specialNeedsCount(): int
    {
        $key = Setting::get('report_special_needs_key');
        if (! filled($key)) {
            return 0;
        }

        return $this->registrants->all()
            ->filter(fn (Model $user): bool => $user->registrationAnswers()->has($key))
            ->count();
    }

    /**
     * Registrant headcount per arrival day, keyed by the day's label and in
     * day order — the same grouping {@see ArrivalSchedule::arrivalsByDay()}
     * feeds the Arrivals report from.
     *
     * @return Collection<string, int>
     */
    public function arrivalsByDay(): Collection
    {
        return $this->arrivals->arrivalsByDay()
            ->mapWithKeys(fn (Collection $group, string $day): array => [$this->arrivals->dayLabel($day) => $group->count()]);
    }

    /** Scheduled shuttle runs, arrivals and returns combined — see {@see ShuttlePlanner}. */
    public function shuttleRunsCount(): int
    {
        $runs = fn (Collection $days): int => $days->sum(fn (array $day): int => count($day['runs']));

        return $runs($this->shuttles->arrivalRuns()) + $runs($this->shuttles->returnRuns());
    }

    /**
     * Room-assignment coverage over every registrant and guest (both types
     * are room-eligible — see {@see RoomAssigner}).
     *
     * @return array{assigned: int, total: int, complete: bool}
     */
    public function roomAssignmentCoverage(): array
    {
        return $this->coverage($this->occupants(), RoomAssignment::all());
    }

    /**
     * Prayer Pals coverage over every registrant plus each opted-in adult
     * guest — the same pool {@see PrayerPalsController}
     * groups.
     *
     * @return array{assigned: int, total: int, complete: bool}
     */
    public function prayerPalsCoverage(): array
    {
        $all = $this->registrants->all();
        $pool = $all->concat(
            $all->flatMap(fn (Model $u) => $u->guests)->filter(fn (Guest $g) => $this->guestQuestions->prayerPalsOptedIn($g))
        )->values();

        return $this->coverage($pool, PrayerPalsAssignment::all());
    }

    /** Every registrant plus every one of their non-attending guests. */
    private function occupants(): Collection
    {
        $all = $this->registrants->all();

        return $all->concat($all->flatMap(fn (Model $u) => $u->guests))->values();
    }

    /** How many of a pool's members have one of the given assignment rows. */
    private function coverage(Collection $pool, Collection $assignments): array
    {
        $key = fn (Model $o): string => $o->getMorphClass().':'.$o->getKey();
        $assignedKeys = $assignments->map(fn ($a) => $a->assignable_type.':'.$a->assignable_id);

        $total = $pool->count();
        $assigned = $pool->filter(fn (Model $o): bool => $assignedKeys->contains($key($o)))->count();

        return ['assigned' => $assigned, 'total' => $total, 'complete' => $total > 0 && $assigned === $total];
    }
}
