<?php

namespace ConferenceTools\Registration\Services\Search;

use ConferenceTools\Registration\Models\Draft;
use ConferenceTools\Registration\Models\Guest;
use ConferenceTools\Registration\Services\DraftGuests;
use ConferenceTools\Registration\Services\RegistrationDeleter;
use ConferenceTools\Registration\Support\Search\SearchTarget;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turns the admin search's selected answer hits into registrations to
 * delete: the confirmation dialog's contents, and the deletion itself once
 * the admin has chosen for each guest and named each departing leader's
 * successor.
 */
class RegistrationDeletion
{
    public function __construct(
        private RegistrantLabels $labels,
        private DraftGuests $draftGuests,
        private RegistrationDeleter $deleter,
    ) {}

    /**
     * The dialog's rows: whole registrations selected directly, then guest
     * selections still awaiting the admin's guest-or-registration choice.
     *
     * @param  Collection<int, SearchTarget>  $targets
     * @return array{registrants: list<array<string, mixed>>, guests: list<array<string, mixed>>}
     */
    public function preview(Collection $targets): array
    {
        [$guestTargets, $userTargets] = $targets->partition(fn (SearchTarget $t): bool => $t->isGuest());
        $direct = $userTargets->pluck('userId')->unique()->values();
        $guestTargets = $guestTargets->reject(fn (SearchTarget $t): bool => $direct->contains($t->userId))
            ->unique(fn (SearchTarget $t): string => $t->encode());
        $users = $this->users($direct->concat($guestTargets->pluck('userId')));

        return [
            'registrants' => $direct->filter(fn (int $id): bool => $users->has($id))
                ->map(fn (int $id): array => $this->registrant($users->get($id), $direct))
                ->values()->all(),
            'guests' => $guestTargets->map(fn (SearchTarget $t): ?array => $this->guest($t, $users->get($t->userId), $direct))
                ->filter()->values()->all(),
        ];
    }

    /**
     * The registrants and lone guests a submission deletes: the direct
     * registrants, plus each guest's registrant when the admin chose the
     * whole registration; the remaining guests go alone.
     *
     * @param  list<int>  $registrantIds
     * @param  array<string, string>  $guestChoices  encoded guest target => "guest" or "registrant"
     * @return array{users: Collection<int, Model>, guests: Collection<int, SearchTarget>}
     */
    public function resolve(array $registrantIds, array $guestChoices): array
    {
        $guestTargets = collect($guestChoices)
            ->map(fn (string $choice, string $encoded): array => [SearchTarget::decode($encoded), $choice])
            ->filter(fn (array $pair): bool => $pair[0]?->isGuest() ?? false);
        $userIds = collect($registrantIds)
            ->concat($guestTargets->filter(fn (array $pair): bool => $pair[1] === 'registrant')->map(fn (array $pair): int => $pair[0]->userId))
            ->map(fn ($id): int => (int) $id)
            ->unique();
        $users = $this->users($userIds);

        return [
            'users' => $users->values(),
            'guests' => $guestTargets
                ->filter(fn (array $pair): bool => $pair[1] === 'guest' && ! $users->has($pair[0]->userId))
                ->map(fn (array $pair): SearchTarget => $pair[0])
                ->values(),
        ];
    }

    /**
     * The members who could take over a departing group leader's group —
     * null when the user leads no group; empty when no one would remain.
     *
     * @param  Collection<int, int>  $departing  ids of every user being deleted
     */
    public function successors(Model $user, Collection $departing): ?Collection
    {
        if (! $user->is_group_admin || ! $user->group_id) {
            return null;
        }

        return config('registration.user_model')::query()
            ->where('group_id', $user->group_id)
            ->whereKeyNot($departing->concat([$user->getKey()])->all())
            ->get()
            ->map(fn (Model $member): array => ['id' => $member->getKey(), 'name' => $this->labels->name($member)]);
    }

    /**
     * Delete the resolved registrations and guests in one transaction.
     *
     * @param  array{users: Collection<int, Model>, guests: Collection<int, SearchTarget>}  $plan  from {@see resolve()}
     * @param  array<int, int>  $leaders  departing leader id => successor id
     * @return array{registrations: int, guests: int}
     */
    public function delete(array $plan, array $leaders): array
    {
        return DB::transaction(function () use ($plan, $leaders): array {
            $guests = $plan['guests']->filter(fn (SearchTarget $target): bool => $this->deleteGuest($target))->count();
            $newLeaders = $this->users(array_values($leaders));

            foreach ($plan['users'] as $user) {
                $this->deleter->deleteRegistrant($user, $newLeaders->get($leaders[$user->getKey()] ?? 0));
            }

            return ['registrations' => $plan['users']->count(), 'guests' => $guests];
        });
    }

    /** Delete one guest target, reporting whether it still existed. */
    private function deleteGuest(SearchTarget $target): bool
    {
        if ($target->guestId !== null) {
            $guest = Guest::where('user_id', $target->userId)->find($target->guestId);
            if ($guest) {
                $this->deleter->deleteGuest($guest);
            }

            return $guest !== null;
        }

        $draft = Draft::where('user_id', $target->userId)->first();
        if (! $draft || ! $this->draftGuests->find($draft, $target->draftGuestId)) {
            return false;
        }

        $this->deleter->deleteDraftGuest($draft, $target->draftGuestId);

        return true;
    }

    /**
     * A directly selected registration's dialog row.
     *
     * @param  Collection<int, int>  $direct
     * @return array<string, mixed>
     */
    private function registrant(Model $user, Collection $direct): array
    {
        return [
            'id' => $user->getKey(),
            'name' => $this->labels->name($user),
            'guests' => $this->guestCount($user),
            'successors' => $this->successors($user, $direct),
        ];
    }

    /**
     * A selected guest's dialog row, or null when the guest is gone.
     *
     * @param  Collection<int, int>  $direct
     * @return array<string, mixed>|null
     */
    private function guest(SearchTarget $target, ?Model $owner, Collection $direct): ?array
    {
        $label = $owner ? $this->guestLabel($target) : null;
        if ($label === null) {
            return null;
        }

        return ['target' => $target->encode(), 'label' => $label, 'owner' => $this->registrant($owner, $direct)];
    }

    /** A guest target's label, or null when the guest no longer exists. */
    private function guestLabel(SearchTarget $target): ?string
    {
        if ($target->guestId !== null) {
            $guest = Guest::where('user_id', $target->userId)->find($target->guestId);

            return $guest ? $this->labels->guest($guest) : null;
        }

        $draft = Draft::where('user_id', $target->userId)->first();
        $guest = $draft ? $this->draftGuests->find($draft, $target->draftGuestId) : null;

        return $guest ? $this->labels->guestOfType($guest['type']) : null;
    }

    /** How many guests, committed and drafted, a registrant has. */
    private function guestCount(Model $user): int
    {
        $draft = Draft::where('user_id', $user->getKey())->first();

        return Guest::where('user_id', $user->getKey())->count() + ($draft ? count($this->draftGuests->all($draft)) : 0);
    }

    /**
     * The host users with the given ids, keyed by id.
     *
     * @return Collection<int, Model>
     */
    private function users(iterable $ids): Collection
    {
        return config('registration.user_model')::query()
            ->whereKey(collect($ids)->unique()->values()->all())
            ->get()
            ->keyBy(fn (Model $user): int => $user->getKey());
    }
}
