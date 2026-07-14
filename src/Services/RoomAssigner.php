<?php

namespace ConferenceTools\Registration\Services;

use ConferenceTools\Registration\Enums\Gender;
use ConferenceTools\Registration\Enums\GuestType;
use ConferenceTools\Registration\Enums\RoomDesignation;
use ConferenceTools\Registration\Models\Guest;
use ConferenceTools\Registration\Models\Room;
use ConferenceTools\Registration\Models\RoomAssignment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The room-assignment first pass. The configured questions tell us who is a
 * man, who is a woman, and who wants to room with whom — never quite enough
 * to finish the job, so this pass only FILLS VACANCIES for occupants not yet
 * housed and leaves every existing assignment alone; the admin corrects the
 * result on the assignments console.
 *
 * The candidate pool is every registrant plus every one of their
 * non-attending guests (adult and minor alike — both are room-eligible). A
 * guest has their own gender and roommate-preference questions (see
 * {@see GuestQuestions}) and is matched by name against the *same* combined
 * pool as registrants, exactly like the user requested: a guest can name a
 * registrant, another guest, or be named by either.
 *
 * Placement order: desired-roommate pairs across genders (couples, mostly)
 * into couples-designated rooms first, then mutually/one-way desired
 * roommate pairs of the same gender, then the remaining singles — men into
 * men-only rooms, women into women-only rooms. A pair that doesn't fit stays
 * together and unassigned (splitting a pair across the single-gender wings
 * is the admin's call to make, not ours), and occupants whose gender can't be
 * derived are left unassigned too.
 *
 * A Minor guest is never placed into a room alongside an adult who isn't
 * their own host registrant or that host's own adult guest (see
 * {@see compatible()}) — checked against both the room's existing occupants
 * and whoever else this pass has already planned for it. A pairing or
 * placement this rule would violate is skipped, leaving those occupants
 * unassigned for the admin to place by hand instead; the admin's own manual
 * assign/move actions are never subject to this check.
 */
class RoomAssigner
{
    /** Beds planned per room during this pass, on top of stored assignments. */
    private array $planned = [];

    /** Occupants planned per room this pass, for the minor/family check. */
    private array $plannedOccupants = [];

    /** Occupants already stored in each room, resolved from the pool. */
    private array $existingOccupants = [];

    public function __construct(
        private Registrants $registrants,
        private ReportQuestions $questions,
        private GuestQuestions $guestQuestions,
    ) {}

    /**
     * Run the first pass. Returns how many occupants were housed and who
     * remains unassigned.
     *
     * @return array{assigned: int, unassigned: Collection<int, Model>}
     */
    public function assign(): array
    {
        $this->planned = [];
        $this->plannedOccupants = [];
        $rooms = Room::with('assignments')->orderBy('wing')->orderBy('floor')->orderBy('name')->get();

        $pool = $this->pool();
        $poolByKey = $pool->keyBy(fn (Model $o): string => $this->keyOf($o));
        $this->existingOccupants = $rooms->mapWithKeys(fn (Room $room) => [
            $room->id => $room->assignments
                ->map(fn (RoomAssignment $a) => $poolByKey->get($this->key($a->assignable_type, $a->assignable_id)))
                ->filter()
                ->values(),
        ])->all();

        $housed = RoomAssignment::query()->get()->map(fn (RoomAssignment $a) => $this->key($a->assignable_type, $a->assignable_id));
        $pending = $pool->reject(fn (Model $o): bool => $housed->contains($this->keyOf($o)))->values();

        $rows = [];

        // Cross-gender roommate wishes (couples, mostly) are the only ones
        // who may share across genders; same-gender wishes are left for the
        // per-gender pass below so they get a shot at a same-gender room
        // first.
        [$couples, $singles] = $this->pairs(
            $pending,
            fn (Model $o): ?string => $this->roommateNameOf($o),
            fn (Model $a, Model $b): bool => $this->genderOf($a) !== null
                && $this->genderOf($b) !== null
                && $this->genderOf($a) !== $this->genderOf($b),
        );
        foreach ($couples as $couple) {
            $rows = [...$rows, ...$this->placePair($rooms->where('designation', RoomDesignation::Couples), $couple)];
        }

        foreach (Gender::cases() as $gender) {
            $pool = $singles->filter(fn (Model $o): bool => $this->genderOf($o) === $gender)->values();
            $zone = $rooms->where('designation', RoomDesignation::forGender($gender));

            // Desired roommates: mutual wishes first, then one-way ones.
            [$pairs, $alone] = $this->pairs($pool, fn (Model $o): ?string => $this->roommateNameOf($o));
            foreach ($pairs as $pair) {
                $rows = [...$rows, ...$this->placePair($zone, $pair)];
            }

            // A pair that didn't fit together falls back to single placement.
            foreach ($this->withoutPlaced($pool, $rows) as $single) {
                $rows = [...$rows, ...$this->placeOne($zone, $single)];
            }
        }

        DB::transaction(function () use ($rows): void {
            foreach ($rows as $row) {
                RoomAssignment::create($row);
            }
        });

        return [
            'assigned' => count($rows),
            'unassigned' => $this->withoutPlaced($pending, $rows),
        ];
    }

    /**
     * Pair up a pool by a named-person wish: mutual matches first, then
     * one-way ones (A names B even though B named no one). Names are matched
     * against each occupant's normalized name — the "mostly" in deriving
     * couples. An optional $allowed predicate can veto an otherwise-matching
     * pair (used to keep the cross-gender pass from also catching
     * same-gender wishes, which belong to the later per-gender pass).
     *
     * @param  ?callable(Model, Model): bool  $allowed
     * @return array{0: array<int, array{Model, Model}>, 1: Collection<int, Model>}
     */
    private function pairs(Collection $pool, callable $wishedName, ?callable $allowed = null): array
    {
        $allowed ??= fn (Model $a, Model $b): bool => true;

        $byName = [];
        foreach ($pool as $occupant) {
            $byName[$this->normalizedNameOf($occupant)][] = $occupant;
        }

        $paired = [];
        $pairs = [];
        foreach ([true, false] as $requireMutual) {
            foreach ($pool as $occupant) {
                if (isset($paired[$this->keyOf($occupant)])) {
                    continue;
                }

                $other = $this->matchFor($occupant, $wishedName, $byName, $paired, $requireMutual, $allowed);
                if ($other !== null) {
                    $paired[$this->keyOf($occupant)] = $paired[$this->keyOf($other)] = true;
                    $pairs[] = [$occupant, $other];
                }
            }
        }

        return [$pairs, $pool->reject(fn (Model $o): bool => isset($paired[$this->keyOf($o)]))->values()];
    }

    /**
     * The first still-unpaired occupant matching this occupant's named-person
     * wish, or null while the wish is blank or nobody acceptable matches.
     *
     * @param  array<string, array<int, Model>>  $byName  pool occupants grouped by normalized name
     * @param  array<string, true>  $paired  keys of occupants already paired
     * @param  ?callable(Model, Model): bool  $allowed
     */
    private function matchFor(Model $occupant, callable $wishedName, array $byName, array $paired, bool $requireMutual, callable $allowed): ?Model
    {
        $wish = $this->wishOf($occupant, $wishedName);
        if ($wish === null) {
            return null;
        }

        foreach ($byName[$wish] ?? [] as $other) {
            if ($this->acceptableMatch($occupant, $other, $wishedName, $paired, $requireMutual, $allowed)) {
                return $other;
            }
        }

        return null;
    }

    /** An occupant's roommate wish, normalized for matching, or null when blank. */
    private function wishOf(Model $occupant, callable $wishedName): ?string
    {
        $name = $wishedName($occupant);

        return filled($name) ? Registrants::normalize($name) : null;
    }

    /**
     * Whether $other can be paired with $occupant: someone else, still
     * unpaired, wishing back when mutuality is required, and not vetoed.
     *
     * @param  array<string, true>  $paired  keys of occupants already paired
     * @param  ?callable(Model, Model): bool  $allowed
     */
    private function acceptableMatch(Model $occupant, Model $other, callable $wishedName, array $paired, bool $requireMutual, callable $allowed): bool
    {
        return $this->keyOf($other) !== $this->keyOf($occupant)
            && ! isset($paired[$this->keyOf($other)])
            && (! $requireMutual || $this->wishOf($other, $wishedName) === $this->normalizedNameOf($occupant))
            && $allowed($occupant, $other);
    }

    /** A pair shares a room, so both go wherever two beds are still free. */
    private function placePair(Collection $zone, array $pair): array
    {
        if (! $this->compatible($pair[0], $pair[1])) {
            return [];
        }

        foreach ($zone as $room) {
            if ($this->vacancies($room) >= 2 && $this->roomAccepts($room, $pair)) {
                return [$this->plan($room, $pair[0]), $this->plan($room, $pair[1])];
            }
        }

        return [];
    }

    /** Put a single occupant into the first room in the zone with a free bed. */
    private function placeOne(Collection $zone, Model $occupant): array
    {
        foreach ($zone as $room) {
            if ($this->vacancies($room) >= 1 && $this->roomAccepts($room, [$occupant])) {
                return [$this->plan($room, $occupant)];
            }
        }

        return [];
    }

    /** A room's free beds after this run's planned placements. */
    private function vacancies(Room $room): int
    {
        return $room->vacancies() - ($this->planned[$room->id] ?? 0);
    }

    /**
     * Whether adding $newcomers to $room keeps every Minor there rooming
     * only with their own host attendee or that host's own adult guests —
     * checked against the room's stored occupants and whoever this pass has
     * already planned for it (see {@see compatible()}).
     */
    private function roomAccepts(Room $room, array $newcomers): bool
    {
        $others = ($this->existingOccupants[$room->id] ?? collect())->concat($this->plannedOccupants[$room->id] ?? collect());

        foreach ($newcomers as $newcomer) {
            foreach ($others as $existing) {
                if (! $this->compatible($newcomer, $existing)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Whether $a and $b may share a room: same minor-or-not status, or the
     * same family (one is the other's host registrant, or both are that
     * registrant's guests) — a Minor guest may never room with an adult
     * (registrant or adult guest) from outside their own family.
     */
    private function compatible(Model $a, Model $b): bool
    {
        return $this->isMinor($a) === $this->isMinor($b) || $this->familyKeyOf($a) === $this->familyKeyOf($b);
    }

    /** Whether this occupant is a non-attending Minor guest. */
    private function isMinor(Model $occupant): bool
    {
        return $occupant instanceof Guest && $occupant->type === GuestType::Minor;
    }

    /** A key shared by a registrant and all of their own guests. */
    private function familyKeyOf(Model $occupant): string
    {
        return 'family:'.($occupant instanceof Guest ? $occupant->user_id : $occupant->getKey());
    }

    /** Reserve a bed and produce the assignment row for an occupant. */
    private function plan(Room $room, Model $occupant): array
    {
        $this->planned[$room->id] = ($this->planned[$room->id] ?? 0) + 1;
        $this->plannedOccupants[$room->id] = ($this->plannedOccupants[$room->id] ?? collect())->push($occupant);

        return ['room_id' => $room->id, 'assignable_type' => $occupant->getMorphClass(), 'assignable_id' => $occupant->getKey()];
    }

    /** @return Collection<int, Model> the pool minus everyone already placed */
    private function withoutPlaced(Collection $pool, array $rows): Collection
    {
        $placed = collect($rows)->map(fn (array $row) => $this->key($row['assignable_type'], $row['assignable_id']));

        return $pool->reject(fn (Model $o): bool => $placed->contains($this->keyOf($o)))->values();
    }

    /** Every registrant plus every one of their guests (adult and minor alike — both are room-eligible). */
    private function pool(): Collection
    {
        $registrants = $this->registrants->all();

        return $registrants->concat($registrants->flatMap(fn (Model $u) => $u->guests))->values();
    }

    /** An occupant's gender answer, guest or registrant. */
    private function genderOf(Model $occupant): ?Gender
    {
        return $occupant instanceof Guest ? $this->guestQuestions->gender($occupant) : $this->questions->gender($occupant);
    }

    /** An occupant's roommate-preference answer, guest or registrant. */
    private function roommateNameOf(Model $occupant): ?string
    {
        return $occupant instanceof Guest ? $this->guestQuestions->roommateName($occupant) : $this->questions->roommateName($occupant);
    }

    /** An occupant's report name, normalized for matching. */
    private function normalizedNameOf(Model $occupant): string
    {
        return $occupant instanceof Guest ? $this->guestQuestions->normalizedName($occupant) : $this->registrants->normalizedName($occupant);
    }

    /** A stable string key for a mixed User/Guest pool — ids from different tables can collide numerically. */
    private function keyOf(Model $occupant): string
    {
        return $this->key($occupant->getMorphClass(), $occupant->getKey());
    }

    /** A pool-wide unique key for an occupant (type plus id). */
    private function key(string $type, int|string $id): string
    {
        return $type.':'.$id;
    }
}
