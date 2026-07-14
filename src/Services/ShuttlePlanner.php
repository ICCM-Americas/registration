<?php

namespace ConferenceTools\Registration\Services;

use ConferenceTools\Registration\Models\Guest;
use ConferenceTools\Registration\Models\Setting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * The airport shuttle schedules, computed from the flight-time questions for
 * the registrants (and their non-attending guests, adult and minor alike —
 * both are shuttle-eligible) who arrive or leave by air (most don't — see
 * {@see ArrivalSchedule} for the everyone-arrives-some-day list). A guest is
 * assumed to travel with their registrant, so there are no separate
 * Guest-scope arrival/flight questions — a guest simply inherits their
 * registrant's own arrival day and flight times, and rides the same runs.
 *
 * Arrivals: fewest trips of a seats-limited shuttle such that no one waits at
 * the airport more than {@see MAX_WAIT_MINUTES} after landing. Greedy over
 * each day's landing times: open a pickup window at the earliest unserved
 * landing, take up to a shuttle-load of landings inside it, and depart when
 * the last of them has landed.
 *
 * Returns: everyone leaves on the same day, so — unlike arrivals — there's no
 * day question to group by; one morning run and one {@see AFTERNOON_RUN} run
 * cover every air departure (each possibly several shuttles, by headcount ÷
 * seats, up to the configured fleet size — whoever doesn't fit is overflow
 * for hand scheduling). Everyone must reach the airport {@see
 * CHECK_IN_MINUTES} before take-off, and the ride takes the configured travel
 * time; whoever the afternoon run would get there too late for goes on the
 * morning run, which departs early enough for the earliest such flight. All
 * flight times are local.
 */
class ShuttlePlanner
{
    /** Seats per shuttle (runtime setting; a typical minibus while unset). */
    public const SHUTTLE_SEATS = 'shuttle_seats';

    public const DEFAULT_SEATS = 12;

    /** Shuttles available to run concurrently (runtime setting; a small fleet while unset). */
    public const SHUTTLE_COUNT = 'shuttle_count';

    public const DEFAULT_COUNT = 2;

    /** Minutes the ride between conference and airport takes (runtime setting). */
    public const TRAVEL_MINUTES = 'shuttle_travel_minutes';

    public const DEFAULT_TRAVEL_MINUTES = 60;

    /** No arriving registrant waits at the airport longer than this. */
    public const MAX_WAIT_MINUTES = 180;

    /** Everyone must be at the airport this long before their return flight. */
    public const CHECK_IN_MINUTES = 120;

    /** The fixed afternoon return run (local time). */
    public const AFTERNOON_RUN = '13:00';

    public function __construct(
        private Registrants $registrants,
        private ReportQuestions $questions,
        private ArrivalSchedule $arrivals,
    ) {}

    /** The configured seats per shuttle. */
    public function seats(): int
    {
        $seats = (int) Setting::get(self::SHUTTLE_SEATS);

        return $seats > 0 ? $seats : self::DEFAULT_SEATS;
    }

    /** The configured one-way travel time in minutes. */
    public function travelMinutes(): int
    {
        $minutes = (int) Setting::get(self::TRAVEL_MINUTES);

        return $minutes > 0 ? $minutes : self::DEFAULT_TRAVEL_MINUTES;
    }

    /** The configured fleet size. */
    public function shuttleCount(): int
    {
        $count = (int) Setting::get(self::SHUTTLE_COUNT);

        return $count > 0 ? $count : self::DEFAULT_COUNT;
    }

    /** Persist the shuttle settings; null clears one back to its default. */
    public function updateSettings(?int $seats, ?int $travelMinutes, ?int $shuttleCount): void
    {
        Setting::put(self::SHUTTLE_SEATS, $seats !== null ? (string) $seats : null);
        Setting::put(self::TRAVEL_MINUTES, $travelMinutes !== null ? (string) $travelMinutes : null);
        Setting::put(self::SHUTTLE_COUNT, $shuttleCount !== null ? (string) $shuttleCount : null);
    }

    /**
     * The pickup schedule, one entry per arrival day (in arrival-day order):
     * ['day', 'label', 'count', 'runs' => [['time', 'passengers']],
     * 'unscheduled'], 'count' being the day's whole headcount, scheduled and
     * not. A passenger lands on "unscheduled" when their flight-time answer
     * can't be read as a clock time — the admin schedules those by hand, or
     * fixes the answer through the schedule page's flight editor.
     *
     * @return Collection<int, array{day: string, label: string, count: int, runs: array, unscheduled: Collection}>
     */
    public function arrivalRuns(): Collection
    {
        return $this->flyersByDay(
            $this->shuttlePool(),
            fn (Model $o): ?string => $this->arrivalDayOf($o),
            fn (Model $o): ?string => $this->flightArrivalTimeOf($o),
            ReportQuestions::ARRIVAL_KEY,
        )->map(function (array $group): array {
            $runs = [];
            $waiting = $group['timed'];
            while ($waiting->isNotEmpty()) {
                $windowEnd = $waiting->first()['minutes'] + self::MAX_WAIT_MINUTES;
                $batch = $waiting->takeWhile(fn (array $p): bool => $p['minutes'] <= $windowEnd)->take($this->seats());

                $runs[] = [
                    'time' => $this->clock($batch->last()['minutes']),
                    'passengers' => $batch->pluck('user'),
                ];
                $waiting = $waiting->slice($batch->count())->values();
            }

            return [
                'day' => $group['day'],
                'label' => $this->arrivals->dayLabel($group['day']),
                'count' => $group['timed']->count() + $group['untimed']->count(),
                'runs' => $runs,
                'unscheduled' => $group['untimed'],
            ];
        })->values();
    }

    /**
     * The return schedule. Unlike arrivals, everyone leaves the same day, so
     * there's no day question to group by — at most one entry, its 'day' and
     * 'label' both blank: ['day', 'label', 'count', 'runs' => [['time',
     * 'shuttles', 'passengers']], 'unscheduled']. At most two runs — morning
     * and {@see AFTERNOON_RUN} — each sized in whole shuttles by headcount ÷
     * seats.
     *
     * @return Collection<int, array{day: string, label: string, count: int, runs: array, unscheduled: Collection}>
     */
    public function returnRuns(): Collection
    {
        $flyers = $this->shuttlePool()
            ->filter(fn (Model $o): bool => $this->flightDepartureTimeOf($o) !== null)
            ->values();

        if ($flyers->isEmpty()) {
            return collect();
        }

        [$timed, $untimed] = $flyers
            ->map(fn (Model $o): array => ['user' => $o, 'minutes' => $this->minutesOrNull($this->flightDepartureTimeOf($o))])
            ->partition(fn (array $p): bool => $p['minutes'] !== null);
        $timed = $timed->sortBy('minutes')->values();

        // The afternoon run reaches the airport at 13:00 + travel; a flight
        // works for it only if that still beats the check-in cut-off.
        $afternoonAtAirport = $this->minutes(self::AFTERNOON_RUN) + $this->travelMinutes();
        [$afternoon, $morning] = $timed->partition(
            fn (array $p): bool => $afternoonAtAirport <= $p['minutes'] - self::CHECK_IN_MINUTES
        );

        $runs = [];
        if ($morning->isNotEmpty()) {
            // Departs early enough for the tightest flight on board.
            $departure = $morning->first()['minutes'] - self::CHECK_IN_MINUTES - $this->travelMinutes();
            $runs[] = $this->run($this->clock(max(0, $departure)), $morning);
        }
        if ($afternoon->isNotEmpty()) {
            $runs[] = $this->run(self::AFTERNOON_RUN, $afternoon);
        }

        return collect([[
            'day' => '',
            'label' => '',
            'count' => $timed->count() + $untimed->count(),
            'runs' => $runs,
            'unscheduled' => $untimed->pluck('user')->values(),
        ]]);
    }

    /**
     * A flight-time answer normalized to "HH:MM", or null when the runs above
     * couldn't read it — how the flight editor shows the admin whether their
     * correction took.
     */
    public function readableTime(string $answer): ?string
    {
        $minutes = $this->minutesOrNull($answer);

        return $minutes === null ? null : $this->clock($minutes);
    }

    /**
     * A run's passengers as a display-ready, comma-joined name list: each
     * name followed by that passenger's own flight time in parentheses
     * (arrival for pickups, departure for returns) — individual times can
     * differ within a run's wait window, unlike the run's own single
     * departure time. Guests are regrouped to sit right after their
     * attendee, rather than wherever their tied flight time happened to
     * sort them.
     */
    public function passengerList(Collection $passengers, string $flight, GuestQuestions $guestQuestions, ReportQuestions $questions): string
    {
        return $passengers
            ->groupBy(fn (Model $o): int|string => $o instanceof Guest ? $o->user_id : $o->getKey())
            ->flatten(1)
            ->map(fn (Model $o): string => $guestQuestions->occupantFullName($o, $questions).' ('.$this->flightTimeOf($o, $flight).')')
            ->implode(', ');
    }

    /** A single passenger's own readable flight time — see {@see passengerList()}. */
    private function flightTimeOf(Model $occupant, string $flight): ?string
    {
        $raw = $flight === 'arrival' ? $this->flightArrivalTimeOf($occupant) : $this->flightDepartureTimeOf($occupant);

        return $raw === null ? null : $this->readableTime($raw);
    }

    // -- Helpers ---------------------------------------------------------------

    /**
     * The pool's occupants who answered the flight-time question — the ones
     * flying — grouped per day, split into flight-time-sorted ['minutes',
     * 'user'] rows and the untimed leftovers whose answers didn't parse.
     *
     * @return Collection<int, array{day: string, timed: Collection, untimed: Collection}>
     */
    private function flyersByDay(Collection $pool, callable $dayOf, callable $timeOf, string $daySetting): Collection
    {
        return $this->arrivals->byDayFor($pool, $dayOf, $daySetting)
            ->map(function (Collection $group, string $day) use ($timeOf): ?array {
                $flyers = $group->filter(fn (Model $o): bool => $timeOf($o) !== null)->values();
                if ($flyers->isEmpty()) {
                    return null;
                }

                [$timed, $untimed] = $flyers
                    ->map(fn (Model $o): array => ['user' => $o, 'minutes' => $this->minutesOrNull($timeOf($o))])
                    ->partition(fn (array $p): bool => $p['minutes'] !== null);

                return [
                    'day' => $day,
                    'timed' => $timed->sortBy('minutes')->values(),
                    'untimed' => $untimed->pluck('user')->values(),
                ];
            })
            ->filter()
            ->values();
    }

    /** Every registrant plus every one of their non-attending guests (both types — both are shuttle-eligible). */
    private function shuttlePool(): Collection
    {
        $registrants = $this->registrants->all();

        return $registrants->concat($registrants->flatMap(fn (Model $u) => $u->guests))->values();
    }

    /** A guest reads their own registrant's arrival/flight answers — see the class doc-comment. */
    private function registrantOf(Model $occupant): Model
    {
        return $occupant instanceof Guest ? $occupant->user : $occupant;
    }

    /** A rider's arrival-day answer. */
    private function arrivalDayOf(Model $occupant): ?string
    {
        return $this->questions->arrivalDay($this->registrantOf($occupant));
    }

    /** A rider's parsed flight arrival time. */
    private function flightArrivalTimeOf(Model $occupant): ?string
    {
        return $this->questions->flightArrivalTime($this->registrantOf($occupant));
    }

    /** A rider's parsed flight departure time. */
    private function flightDepartureTimeOf(Model $occupant): ?string
    {
        return $this->questions->flightDepartureTime($this->registrantOf($occupant));
    }

    /**
     * One departure-time run: its shuttles count, passenger names, and —
     * beyond what the fleet can carry in one go — the overflow passengers
     * left for the admin to schedule by hand. $passengers is sorted by
     * tightest flight first, so the ones with the most slack are the ones
     * bumped.
     */
    private function run(string $time, Collection $passengers): array
    {
        $capacity = $this->seats() * $this->shuttleCount();
        $scheduled = $passengers->take($capacity);

        return [
            'time' => $time,
            'shuttles' => (int) ceil($scheduled->count() / $this->seats()),
            'passengers' => $scheduled->pluck('user'),
            'overflow' => $passengers->slice($capacity)->pluck('user')->values(),
        ];
    }

    /** A clock-time answer as minutes since midnight, or null when unreadable. */
    private function minutesOrNull(string $time): ?int
    {
        $parsed = strtotime('1970-01-01 '.trim($time).' UTC');

        return $parsed === false ? null : intdiv($parsed, 60);
    }

    /** As {@see minutesOrNull()}, for package-supplied times that always parse. */
    private function minutes(string $time): int
    {
        return (int) $this->minutesOrNull($time);
    }

    /** Minutes-since-midnight as a 24-hour HH:MM label. */
    private function clock(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60) % 24, $minutes % 60);
    }
}
