<?php

namespace ConferenceTools\Registration\Services;

use ConferenceTools\Registration\Models\Answer;
use ConferenceTools\Registration\Models\ConferenceArchive;
use ConferenceTools\Registration\Models\Group;
use ConferenceTools\Registration\Models\Guest;
use ConferenceTools\Registration\Models\Payment;
use ConferenceTools\Registration\Models\PrayerPalsAssignment;
use ConferenceTools\Registration\Models\PrayerPalsGroup;
use ConferenceTools\Registration\Models\RoomAssignment;
use Illuminate\Support\Facades\DB;

/**
 * Archives the outgoing conference's registration data into the single
 * retained {@see ConferenceArchive} snapshot — replacing whatever archive
 * already existed — then clears the live tables via {@see AnswerPurge} so
 * registrants can sign up for the next conference from a clean slate.
 * Payments and Prayer Pals groupings are cycle-specific too, so they're
 * captured and cleared here as well; AnswerPurge alone doesn't touch them.
 */
class RegistrationArchiver
{
    public function __construct(private AnswerPurge $purge, private ConferenceEdition $edition) {}

    /**
     * The current registration data, in the shape {@see ConferenceArchive::$data}
     * stores it in — used both to write a fresh archive and, via
     * {@see RegistrationExportBundle}, to export a live CSV bundle without
     * archiving or purging anything. The registrant→group linkage
     * ("registrants") is captured explicitly because {@see AnswerPurge}
     * resets every user's group_id, and it is otherwise nowhere recorded
     * once a rollover has happened.
     */
    public function snapshot(): array
    {
        return [
            'answers' => Answer::query()->get()->toArray(),
            'groups' => Group::query()->get()->toArray(),
            'guests' => Guest::query()->get()->toArray(),
            'registrants' => config('registration.user_model')::query()
                ->whereNotNull('group_id')->get(['id', 'group_id'])->toArray(),
            'payments' => Payment::query()->get()->toArray(),
            'room_assignments' => RoomAssignment::query()->get()->toArray(),
            'prayer_pals_groups' => PrayerPalsGroup::query()->get()->toArray(),
            'prayer_pals_assignments' => PrayerPalsAssignment::query()->get()->toArray(),
        ];
    }

    /** Snapshot then clear this conference's registration data, keeping only the latest archive. */
    public function archive(): void
    {
        DB::transaction(function (): void {
            ConferenceArchive::query()->delete();

            ConferenceArchive::create([
                'conference_name' => $this->edition->name(),
                'conference_year' => $this->edition->year(),
                'archived_at' => now(),
                'data' => $this->snapshot(),
            ]);

            PrayerPalsAssignment::query()->delete();
            PrayerPalsGroup::query()->delete();
            Payment::query()->delete();

            $this->purge->purge();
        });
    }
}
