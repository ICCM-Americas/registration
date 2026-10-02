<?php

namespace ConferenceTools\Registration\Services;

use ConferenceTools\Registration\Models\Answer;
use ConferenceTools\Registration\Models\Draft;
use ConferenceTools\Registration\Models\Group;
use ConferenceTools\Registration\Models\GroupInvite;
use ConferenceTools\Registration\Models\Guest;
use ConferenceTools\Registration\Models\Payment;
use ConferenceTools\Registration\Models\PrayerPalsAssignment;
use ConferenceTools\Registration\Models\RoomAssignment;
use Illuminate\Database\Eloquent\Model;

/**
 * Deletes one registrant's registration, or one of their guests — the
 * per-person counterpart of {@see AnswerPurge}. User accounts are never
 * deleted; only the registration columns the package owns on them are reset.
 */
class RegistrationDeleter
{
    public function __construct(private DraftGuests $draftGuests) {}

    /**
     * Delete everything a registrant registered: answers, guests, draft,
     * payment, invites, and room/Prayer Pals assignments. A group leader hands
     * the group to the given member; a group left with no members is deleted
     * along with its own answers and invites.
     */
    public function deleteRegistrant(Model $user, ?Model $newLeader = null): void
    {
        $morph = $user->getMorphClass();

        Answer::where('owner_type', $morph)->where('owner_id', $user->getKey())->delete();
        Guest::where('user_id', $user->getKey())->get()->each->delete();
        Draft::where('user_id', $user->getKey())->delete();
        Payment::where('user_id', $user->getKey())->delete();
        GroupInvite::where('user_id', $user->getKey())->delete();
        RoomAssignment::where('assignable_type', $morph)->where('assignable_id', $user->getKey())->delete();
        PrayerPalsAssignment::where('assignable_type', $morph)->where('assignable_id', $user->getKey())->delete();

        $this->leaveGroup($user, $newLeader);
    }

    /** Delete one committed guest with their answers and assignments. */
    public function deleteGuest(Guest $guest): void
    {
        $guest->delete();
    }

    /** Drop one guest from a registrant's in-progress draft. */
    public function deleteDraftGuest(Draft $draft, string $guestId): void
    {
        $this->draftGuests->remove($draft, $guestId);
    }

    /** Detach the user from their group, passing leadership on, or deleting the group when no one is left. */
    private function leaveGroup(Model $user, ?Model $newLeader): void
    {
        $group = $user->group_id ? Group::find($user->group_id) : null;

        $user->forceFill(['group_id' => null, 'is_group_admin' => false, 'checked_out' => false])->save();

        if (! $group) {
            return;
        }

        if ($group->users()->doesntExist()) {
            Answer::where('owner_type', $group->getMorphClass())->where('owner_id', $group->id)->delete();
            GroupInvite::where('group_id', $group->id)->delete();
            $group->delete();

            return;
        }

        $newLeader?->forceFill(['is_group_admin' => true])->save();
    }
}
