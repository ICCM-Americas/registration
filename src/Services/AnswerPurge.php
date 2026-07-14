<?php

namespace ConferenceTools\Registration\Services;

use ConferenceTools\Registration\Models\Answer;
use ConferenceTools\Registration\Models\Draft;
use ConferenceTools\Registration\Models\Group;
use ConferenceTools\Registration\Models\Guest;
use ConferenceTools\Registration\Models\RoomAssignment;
use Illuminate\Support\Facades\DB;

/**
 * Resets all registration data to a clean slate — every answer, group, draft,
 * room assignment and non-attending guest, whatever produced them (a real
 * registrant or the demo seeder). Registrant accounts themselves are never
 * touched: only the structural columns the package owns on them (group_id,
 * is_group_admin, checked_out) are reset, since {@see Group::registeredParticipantCount()}
 * reads group_id directly and would otherwise keep reporting stale counts
 * after their group is gone.
 */
class AnswerPurge
{
    /** Delete every registration answer, group, draft, guest, and room assignment — never user accounts. */
    public function purge(): void
    {
        $userModel = config('registration.user_model');

        DB::transaction(function () use ($userModel): void {
            Answer::query()->delete();
            Group::query()->delete();
            Draft::query()->delete();
            RoomAssignment::query()->delete();
            // Guests have no answers of their own left to purge separately —
            // Answer::query()->delete() above already covers them (Answer's
            // owner_type/owner_id is polymorphic across Participant/Group/Guest).
            Guest::query()->delete();

            $userModel::query()->whereNotNull('group_id')->update([
                'group_id' => null,
                'is_group_admin' => false,
                'checked_out' => false,
            ]);
        });
    }
}
