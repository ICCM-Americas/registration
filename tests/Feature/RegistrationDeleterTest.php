<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Enums\GuestType;
use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Models\Answer;
use ConferenceTools\Registration\Models\Draft;
use ConferenceTools\Registration\Models\Group;
use ConferenceTools\Registration\Models\GroupInvite;
use ConferenceTools\Registration\Models\Guest;
use ConferenceTools\Registration\Models\Payment;
use ConferenceTools\Registration\Models\PrayerPalsAssignment;
use ConferenceTools\Registration\Models\RoomAssignment;
use ConferenceTools\Registration\Services\RegistrationDeleter;
use ConferenceTools\Registration\Tests\Concerns\BuildsReportData;
use ConferenceTools\Registration\Tests\Fixtures\User;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;

/** Deleting one registrant's registration, or one of their guests, without touching user accounts or anyone else. */
#[TestDox('Registration Deleter')]
class RegistrationDeleterTest extends TestCase
{
    use BuildsReportData, RefreshDatabase;

    private Group $group;

    private User $leader;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReportQuestions();
        $this->group = $this->makeGroupWithMembers();
        $this->leader = $this->group->admin();
        $this->member = $this->group->users()->where('is_group_admin', false)->first();
    }

    #[TestDox('deleting a registrant removes all their registration data but keeps their account')]
    public function test_deleting_a_registrant_removes_all_their_registration_data(): void
    {
        $guest = $this->makeGuest($this->member, GuestType::Adult, ['guestname' => 'Gus']);
        Draft::factory()->create(['user_id' => $this->member->id]);
        Payment::factory()->create(['user_id' => $this->member->id]);
        GroupInvite::create(['group_id' => $this->group->id, 'token' => 'tok', 'user_id' => $this->member->id]);
        RoomAssignment::factory()->create(['assignable_id' => $this->member->id]);
        PrayerPalsAssignment::factory()->create(['assignable_id' => $this->member->id]);
        RoomAssignment::factory()->forGuest()->create(['assignable_id' => $guest->id]);
        $leaderAnswers = $this->answersOf($this->leader);

        app(RegistrationDeleter::class)->deleteRegistrant($this->member);

        $member = $this->member->fresh();
        $this->assertNotNull($member);
        $this->assertNull($member->group_id);
        $this->assertSame(0, $this->answersOf($member));
        $this->assertSame(0, Guest::count() + Draft::count() + Payment::count() + GroupInvite::count() + RoomAssignment::count() + PrayerPalsAssignment::count());
        $this->assertSame(0, Answer::where('owner_type', $guest->getMorphClass())->count());
        $this->assertSame($leaderAnswers, $this->answersOf($this->leader));
        $this->assertTrue((bool) $this->leader->fresh()->is_group_admin);
        $this->assertNotNull($this->group->fresh());
    }

    #[TestDox('a departing leader hands the group to the chosen member')]
    public function test_a_departing_leader_hands_the_group_to_the_chosen_member(): void
    {
        app(RegistrationDeleter::class)->deleteRegistrant($this->leader, $this->member);

        $this->assertFalse((bool) $this->leader->fresh()->is_group_admin);
        $this->assertTrue((bool) $this->member->fresh()->is_group_admin);
        $this->assertSame($this->member->id, $this->group->fresh()->admin()->id);
    }

    #[TestDox('the last member\'s departure deletes the group with its own answers and invites')]
    public function test_the_last_members_departure_deletes_the_group(): void
    {
        GroupInvite::create(['group_id' => $this->group->id, 'token' => 'open']);
        $deleter = app(RegistrationDeleter::class);

        $deleter->deleteRegistrant($this->member);
        $deleter->deleteRegistrant($this->leader);

        $this->assertNull($this->group->fresh());
        $this->assertSame(0, Answer::where('owner_type', $this->group->getMorphClass())->count());
        $this->assertSame(0, GroupInvite::count());
    }

    #[TestDox('a registrant outside any group is deleted without touching groups')]
    public function test_a_registrant_outside_any_group_is_deleted(): void
    {
        $loner = $this->makeUser();
        $this->storeAnswers($loner, QuestionScope::Participant, ['name' => 'Lone']);

        app(RegistrationDeleter::class)->deleteRegistrant($loner);

        $this->assertSame(0, $this->answersOf($loner));
        $this->assertNotNull($this->group->fresh());
    }

    #[TestDox('deleting a guest leaves their registrant and other guests alone')]
    public function test_deleting_a_guest_leaves_their_registrant_alone(): void
    {
        $gone = $this->makeGuest($this->member, GuestType::Adult, ['guestname' => 'Gone']);
        $kept = $this->makeGuest($this->member, GuestType::Minor, ['guestname' => 'Kept']);
        $answers = $this->answersOf($this->member);

        app(RegistrationDeleter::class)->deleteGuest($gone);

        $this->assertSame([$kept->id], Guest::pluck('id')->all());
        $this->assertSame($answers, $this->answersOf($this->member));
    }

    #[TestDox('deleting a draft guest drops only that entry from the draft')]
    public function test_deleting_a_draft_guest_drops_only_that_entry(): void
    {
        $draft = Draft::factory()->create(['user_id' => $this->member->id, 'guests' => [
            ['id' => 'a', 'type' => 'adult', 'answers' => []],
            ['id' => 'b', 'type' => 'minor', 'answers' => []],
        ]]);

        app(RegistrationDeleter::class)->deleteDraftGuest($draft, 'a');

        $this->assertSame(['b'], array_column($draft->fresh()->guests, 'id'));
    }

    /** How many answers a user owns. */
    private function answersOf(User $user): int
    {
        return Answer::where('owner_type', $user->getMorphClass())->where('owner_id', $user->id)->count();
    }
}
