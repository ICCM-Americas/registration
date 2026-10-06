<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Enums\QuestionType;
use ConferenceTools\Registration\Models\Answer;
use ConferenceTools\Registration\Models\Group;
use ConferenceTools\Registration\Models\Guest;
use ConferenceTools\Registration\Models\Payment;
use ConferenceTools\Registration\Models\PrayerPalsAssignment;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\RoomAssignment;
use ConferenceTools\Registration\Models\Section;
use ConferenceTools\Registration\Services\GuestQuestions;
use ConferenceTools\Registration\Services\RegistrationStatus;
use ConferenceTools\Registration\Tests\Concerns\BuildsRegistrationData;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * The post-commit "Manage Guests" hub on My Registration: add/edit/remove
 * real {@see Guest} rows directly (no wizard draft involved, unlike the
 * pre-commit hub — see GuestControllerTest), gated by the same
 * {@see RegistrationStatus::eligibleToModify()} the Modify feature uses.
 */
#[TestDox('My Guest Controller')]
class MyGuestControllerTest extends TestCase
{
    use BuildsRegistrationData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openRegistration();
        $this->seedGuestQuestions();
    }

    /** A Guest-scope "name" question — Guest-scope questions aren't part of the fixture questionnaire, unlike GuestControllerTest's own copy of this helper. */
    private function seedGuestQuestions(): void
    {
        $guestSection = Section::create([
            'scope' => QuestionScope::Guest->value, 'key' => 'guest-details', 'title' => 'Guest Details', 'position' => 0, 'enabled' => true,
        ]);
        Question::create([
            'section_id' => $guestSection->id, 'key' => 'guestname', 'type' => QuestionType::Text->value,
            'label' => 'Guest name', 'position' => 0, 'required' => false, 'enabled' => true,
        ]);
        app(GuestQuestions::class)->update(['guest_name_key' => 'guestname']);
    }

    #[TestDox('hub 404s for a user who has not registered')]
    public function test_hub_404s_for_a_user_who_has_not_registered(): void
    {
        $this->actingAs($this->makeUser())
            ->get(route('registration.mine.guests'))
            ->assertNotFound();
    }

    #[TestDox('hub shows the registrants committed guests')]
    public function test_hub_shows_the_registrants_committed_guests(): void
    {
        $group = $this->makeGroupWithMembers();
        $leader = $group->admin();
        $guest = Guest::create(['user_id' => $leader->id, 'type' => 'adult', 'position' => 0]);
        $this->storeAnswers($guest, QuestionScope::Guest, ['guestname' => 'Stay Home']);

        $this->actingAs($leader)
            ->get(route('registration.mine.guests'))
            ->assertOk()
            ->assertSee('Stay Home');
    }

    #[TestDox('add edit and remove a committed guest')]
    public function test_add_edit_and_remove_a_committed_guest(): void
    {
        $group = $this->makeGroupWithMembers();
        $leader = $group->admin();
        $this->actingAs($leader);

        $this->get(route('registration.mine.guests'))->assertOk()->assertSee(__('registration::common.guest_list_empty'));

        $this->post(route('registration.mine.guests.store'), ['guest_type' => 'adult', 'guestname' => 'Stay Home'])
            ->assertRedirect(route('registration.mine.guests'));

        $this->assertSame(1, Guest::count());
        $guest = Guest::first();
        $this->assertSame($leader->id, $guest->user_id);
        $this->assertSame('Stay Home', $guest->registrationAnswers()->value('guestname'));

        $this->get(route('registration.mine.guests.edit', $guest))
            ->assertOk()
            ->assertSee('value="Stay Home"', false);

        $this->post(route('registration.mine.guests.update', $guest), ['guestname' => 'Staying Home Still'])
            ->assertRedirect(route('registration.mine.guests'));
        $guest->refreshRegistrationAnswers();
        $this->assertSame('Staying Home Still', $guest->registrationAnswers()->value('guestname'));

        $this->delete(route('registration.mine.guests.destroy', $guest))
            ->assertRedirect(route('registration.mine.guests'));

        $this->assertSame(0, Guest::count());
    }

    #[TestDox('position is assigned after the highest existing guest, even if an earlier one was deleted')]
    public function test_position_is_assigned_after_the_highest_existing_guest_even_if_an_earlier_one_was_deleted(): void
    {
        $group = $this->makeGroupWithMembers();
        $leader = $group->admin();
        $this->actingAs($leader);

        $this->post(route('registration.mine.guests.store'), ['guest_type' => 'adult', 'guestname' => 'First'])->assertRedirect();
        $first = Guest::first();
        $this->post(route('registration.mine.guests.store'), ['guest_type' => 'adult', 'guestname' => 'Second'])->assertRedirect();

        $this->delete(route('registration.mine.guests.destroy', $first))->assertRedirect();

        $this->post(route('registration.mine.guests.store'), ['guest_type' => 'adult', 'guestname' => 'Third'])->assertRedirect();

        $third = Guest::orderBy('id', 'desc')->first();
        $this->assertSame(2, $third->position);
    }

    #[TestDox('a guest owned by another registrant 404s')]
    public function test_a_guest_owned_by_another_registrant_404s(): void
    {
        $group = $this->makeGroupWithMembers();
        $leader = $group->admin();
        $otherGuest = Guest::create(['user_id' => $leader->id, 'type' => 'adult', 'position' => 0]);

        $other = $this->registrant(Group::factory()->create(['name' => 'Other Group']), true);

        $this->actingAs($other);
        $this->get(route('registration.mine.guests.edit', $otherGuest))->assertNotFound();
        $this->post(route('registration.mine.guests.update', $otherGuest), ['guestname' => 'x'])->assertNotFound();
        $this->delete(route('registration.mine.guests.destroy', $otherGuest))->assertNotFound();
    }

    #[TestDox('deleting a guest also deletes its answers, room assignment, and Prayer Pals assignment')]
    public function test_deleting_a_guest_also_deletes_its_answers_room_assignment_and_prayer_pals_assignment(): void
    {
        $group = $this->makeGroupWithMembers();
        $leader = $group->admin();
        $guest = Guest::create(['user_id' => $leader->id, 'type' => 'adult', 'position' => 0]);
        $this->storeAnswers($guest, QuestionScope::Guest, ['guestname' => 'Stay Home']);

        $room = RoomAssignment::factory()->forGuest()->create(['assignable_id' => $guest->id]);
        $pals = PrayerPalsAssignment::factory()->forGuest()->create(['assignable_id' => $guest->id]);

        $guest->delete();

        $this->assertSame(0, Answer::where('owner_type', Guest::class)->where('owner_id', $guest->id)->count());
        $this->assertDatabaseMissing($room->getTable(), ['id' => $room->id]);
        $this->assertDatabaseMissing($pals->getTable(), ['id' => $pals->id]);
    }

    #[TestDox('every action is gated behind eligibility to modify')]
    #[DataProvider('ineligibilityProvider')]
    public function test_every_action_is_gated_behind_eligibility_to_modify(\Closure $makeIneligible): void
    {
        $group = $this->makeGroupWithMembers();
        $leader = $group->admin();
        $guest = Guest::create(['user_id' => $leader->id, 'type' => 'adult', 'position' => 0]);
        $makeIneligible($leader);

        $this->actingAs($leader);
        $this->get(route('registration.mine.guests'))->assertNotFound();
        $this->get(route('registration.mine.guests.create'))->assertNotFound();
        $this->post(route('registration.mine.guests.store'), ['guest_type' => 'adult', 'guestname' => 'x'])->assertNotFound();
        $this->get(route('registration.mine.guests.edit', $guest))->assertNotFound();
        $this->post(route('registration.mine.guests.update', $guest), ['guestname' => 'x'])->assertNotFound();
        $this->delete(route('registration.mine.guests.destroy', $guest))->assertNotFound();

        $this->assertSame(1, Guest::count(), 'no side effect happened: still just the pre-existing guest, store refused and destroy refused');
    }

    public static function ineligibilityProvider(): array
    {
        return [
            'marked paid' => [fn ($leader) => Payment::factory()->paid()->create(['user_id' => $leader->id])],
            'registration closed' => [fn () => app(RegistrationStatus::class)->close()],
        ];
    }
}
