<?php

namespace ConferenceTools\Registration\Services;

use ConferenceTools\Registration\Enums\ReportField;
use ConferenceTools\Registration\Models\PrayerPalsGroup;
use ConferenceTools\Registration\Models\Room;
use Illuminate\Support\Collection;

/**
 * Builds every CSV sheet in a registration export .ZIP from one snapshot (see
 * {@see RegistrationArchiver::snapshot()}): the flattened answers table, plus
 * groups, payments, room assignments and Prayer Pals — the "non-answer" data
 * that has nowhere else to go in a single-sheet CSV format, so it becomes its
 * own file per category instead. Works equally from a freshly gathered
 * snapshot or a decoded ConferenceArchive one.
 */
class RegistrationExportBundle
{
    public function __construct(
        private RegistrationAnswerFlattener $flattener,
        private RegistrationSnapshotIdentities $identities,
    ) {}

    /**
     * @param  array{answers?: array, groups?: array, guests?: array, registrants?: array, payments?: array, room_assignments?: array, prayer_pals_groups?: array, prayer_pals_assignments?: array}  $snapshot
     * @return array<string, array{0: array, 1: Collection}> sheet name (no .csv) => [headers, rows]
     */
    public function sheets(array $snapshot): array
    {
        $identities = $this->identities->resolve($snapshot);

        return [
            'answers' => [$this->flattener->headers(), $this->flattener->rows($snapshot)],
            'groups' => [$this->groupsHeaders(), $this->groupRows($snapshot, $identities)],
            'payments' => [$this->paymentsHeaders(), $this->paymentRows($snapshot, $identities)],
            'room-assignments' => [$this->roomAssignmentsHeaders(), $this->roomAssignmentRows($snapshot, $identities)],
            'prayer-pals' => [$this->prayerPalsHeaders(), $this->prayerPalsRows($snapshot, $identities)],
        ];
    }

    private function groupsHeaders(): array
    {
        return [
            __('registration::admin.export_group_name'),
            __('registration::admin.export_is_group'),
            __('registration::admin.export_checked_out'),
            __('registration::admin.export_members'),
        ];
    }

    private function paymentsHeaders(): array
    {
        return [
            __('registration::admin.export_name'),
            ReportField::Email->label(),
            __('registration::admin.export_paid'),
            __('registration::admin.export_amount'),
            __('registration::admin.export_notes'),
        ];
    }

    private function roomAssignmentsHeaders(): array
    {
        return [
            __('registration::admin.export_room'),
            __('registration::admin.export_occupant_name'),
            __('registration::admin.export_occupant_type'),
        ];
    }

    private function prayerPalsHeaders(): array
    {
        return [
            __('registration::admin.export_prayer_pals_group'),
            __('registration::admin.export_sex'),
            __('registration::admin.export_assignee_name'),
            __('registration::admin.export_assignee_type'),
        ];
    }

    /** One row per Group: name, its two flags, and its members' names (there being nowhere else for that linkage to show up now that group-scope answers are attributed per member instead). */
    private function groupRows(array $snapshot, Collection $identities): Collection
    {
        $userModel = config('registration.user_model');
        $registrants = collect($snapshot['registrants'] ?? []);

        return collect($snapshot['groups'] ?? [])->map(function (array $group) use ($registrants, $identities, $userModel): array {
            $members = $registrants->where('group_id', $group['id'])
                ->map(fn (array $r): ?string => $identities->get($this->identities->ownerKey($userModel, $r['id']))['name'] ?? null)
                ->filter()
                ->implode(', ');

            return [$group['name'], $this->yesNo($group['is_group']), $this->yesNo($group['checked_out']), $members];
        })->values();
    }

    /** One row per Payment: the registrant's identity, then the payment's own fields. */
    private function paymentRows(array $snapshot, Collection $identities): Collection
    {
        $userModel = config('registration.user_model');

        return collect($snapshot['payments'] ?? [])->map(function (array $payment) use ($identities, $userModel): array {
            $identity = $identities->get($this->identities->ownerKey($userModel, $payment['user_id']));

            return [
                $identity['name'] ?? null,
                $identity['email'] ?? null,
                $this->yesNo($payment['is_paid']),
                $payment['amount'],
                $payment['notes'],
            ];
        })->values();
    }

    /** One row per RoomAssignment: the room (looked up live — rooms are configuration, never purged), then the occupant's identity. */
    private function roomAssignmentRows(array $snapshot, Collection $identities): Collection
    {
        $roomsById = Room::query()->get()->keyBy('id');

        return collect($snapshot['room_assignments'] ?? [])->map(function (array $assignment) use ($roomsById, $identities): array {
            $identity = $identities->get($this->identities->ownerKey($assignment['assignable_type'], $assignment['assignable_id']));

            return [
                $roomsById->get($assignment['room_id'])?->fullName(),
                $identity['name'] ?? null,
                $identity['entryType'] ?? null,
            ];
        })->values();
    }

    /** One row per PrayerPalsAssignment: the group (hydrated from the snapshot's own data, since Prayer Pals groupings are cycle-specific and purged), then the assignee's identity. */
    private function prayerPalsRows(array $snapshot, Collection $identities): Collection
    {
        $groupsById = collect($snapshot['prayer_pals_groups'] ?? [])
            ->map(fn (array $row): PrayerPalsGroup => (new PrayerPalsGroup)->forceFill($row))
            ->keyBy('id');

        return collect($snapshot['prayer_pals_assignments'] ?? [])->map(function (array $assignment) use ($groupsById, $identities): array {
            $group = $groupsById->get($assignment['prayer_pals_group_id']);
            $identity = $identities->get($this->identities->ownerKey($assignment['assignable_type'], $assignment['assignable_id']));

            return [
                $group?->label(),
                $group?->sex?->label(),
                $identity['name'] ?? null,
                $identity['entryType'] ?? null,
            ];
        })->values();
    }

    /** A boolean (or truthy scalar, since snapshot arrays came through Model::toArray()) as "Yes"/"No". */
    private function yesNo(mixed $value): string
    {
        return $value ? 'Yes' : 'No';
    }
}
