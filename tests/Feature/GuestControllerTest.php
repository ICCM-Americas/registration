<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Enums\BooleanOperator;
use ConferenceTools\Registration\Enums\ConditionOperator;
use ConferenceTools\Registration\Enums\ConditionSubject;
use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Enums\QuestionType;
use ConferenceTools\Registration\Models\Draft;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\Section;
use ConferenceTools\Registration\Services\GuestQuestions;
use ConferenceTools\Registration\Tests\Concerns\BuildsRegistrationData;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * The Guest List hub and add/edit/remove flow, addressed against the
 * registrant's Draft directly (no need to walk the whole wizard — see
 * RegistrationControllerTest for the full end-to-end commit path).
 */
#[TestDox('Guest Controller')]
class GuestControllerTest extends TestCase
{
    use BuildsRegistrationData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openRegistration();
        $this->seedGuestQuestions();
    }

    /** Move the seeded guest question + Guest-scope name/note (the note conditional on the name being answered). */
    private function seedGuestQuestions(): void
    {
        $triggerSection = Section::create([
            'scope' => QuestionScope::Participant->value, 'key' => 'guest-trigger', 'title' => 'Guests', 'position' => 0, 'enabled' => true,
        ]);
        // The migration already seeded the question (live, in its own
        // dedicated section) — just move it here.
        Question::where('key', Question::GUEST_TRIGGER_KEY)->firstOrFail()->update([
            'section_id' => $triggerSection->id,
        ]);

        $guestSection = Section::create([
            'scope' => QuestionScope::Guest->value, 'key' => 'guest-details', 'title' => 'Guest Details', 'position' => 0, 'enabled' => true,
        ]);
        $name = Question::create([
            'section_id' => $guestSection->id, 'key' => 'guestname', 'type' => QuestionType::Text->value,
            'label' => 'Guest name', 'position' => 0, 'required' => false, 'enabled' => true,
        ]);
        // A note question only visible once the guest's own name is answered —
        // regression-guards that a guest's visibility never bleeds into
        // another owner's answers.
        $note = Question::create([
            'section_id' => $guestSection->id, 'key' => 'note', 'type' => QuestionType::Text->value,
            'label' => 'Note', 'position' => 1, 'required' => false, 'enabled' => true,
        ]);
        $group = $note->conditionGroups()->create(['operator' => BooleanOperator::And->value]);
        $group->conditions()->create(['question_id' => $name->id, 'operator' => ConditionOperator::IsAnswered->value, 'value' => null]);

        app(GuestQuestions::class)->update(['guest_name_key' => 'guestname']);
    }

    #[TestDox('hub 404s until the trigger is answered yes')]
    public function test_hub_404s_until_the_trigger_is_answered_yes(): void
    {
        $user = $this->makeUser();
        Draft::factory()->create(['user_id' => $user->id, 'answers' => []]);

        $this->actingAs($user)->get(route('registration.register.guests'))->assertNotFound();
    }

    #[TestDox('add edit and remove a guest')]
    public function test_add_edit_and_remove_a_guest(): void
    {
        $user = $this->makeUser();
        Draft::factory()->create(['user_id' => $user->id, 'answers' => [Question::GUEST_TRIGGER_KEY => 'Yes']]);
        $this->actingAs($user);

        $this->get(route('registration.register.guests.create'))->assertOk()->assertSee('name="guest_type"', false);

        $this->post(route('registration.register.guests.store'), [
            'guest_type' => 'minor', 'guestname' => 'Kid', 'note' => 'Allergic to peanuts',
        ])->assertRedirect(route('registration.register.guests'));

        $draft = Draft::where('user_id', $user->id)->first();
        $this->assertCount(1, $draft->guests);
        $guestId = $draft->guests[0]['id'];
        $this->assertSame('minor', $draft->guests[0]['type']);
        $this->assertSame(['guestname' => 'Kid', 'note' => 'Allergic to peanuts'], $draft->guests[0]['answers']);

        $this->get(route('registration.register.guests.edit', $guestId))
            ->assertOk()
            ->assertSee('value="Kid"', false)
            // The type is shown but not editable — no radio, just a hidden
            // mirror so a guest_type-conditioned question's live visibility
            // toggle still has something to read the fixed type from.
            ->assertDontSee('type="radio" id="guest_type_', false)
            ->assertSee('type="hidden" name="guest_type" value="minor"', false);

        $this->post(route('registration.register.guests.update', $guestId), ['guestname' => 'Kid Updated'])
            ->assertRedirect(route('registration.register.guests'));

        $draft->refresh();
        $this->assertSame('Kid Updated', $draft->guests[0]['answers']['guestname']);
        $this->assertSame('minor', $draft->guests[0]['type']); // unaffected by update

        $this->delete(route('registration.register.guests.destroy', $guestId))
            ->assertRedirect(route('registration.register.guests'));

        $this->assertSame([], $draft->fresh()->guests);
    }

    #[TestDox('a guests own conditional question is honored independently per guest')]
    public function test_a_guests_own_conditional_question_is_honored_independently_per_guest(): void
    {
        $user = $this->makeUser();
        Draft::factory()->create(['user_id' => $user->id, 'answers' => [Question::GUEST_TRIGGER_KEY => 'Yes']]);
        $this->actingAs($user);

        // "note" requires this guest's own "guestname" to be answered — submitting
        // both together must store the note.
        $this->post(route('registration.register.guests.store'), [
            'guest_type' => 'adult', 'guestname' => 'Ada', 'note' => 'VIP',
        ])->assertRedirect(route('registration.register.guests'));

        $draft = Draft::where('user_id', $user->id)->first();
        $this->assertSame('VIP', $draft->guests[0]['answers']['note']);
    }

    #[TestDox('a guest_type-conditioned question is hidden and its tampered answer dropped for the excluded type')]
    public function test_a_guest_type_conditioned_question_is_hidden_and_ignored_for_the_excluded_type(): void
    {
        $guestSection = Section::where('scope', QuestionScope::Guest->value)->first();
        $photos = Question::create([
            'section_id' => $guestSection->id, 'key' => 'photos', 'type' => QuestionType::Radio->value,
            'label' => 'Photos?', 'position' => 2, 'required' => false, 'enabled' => true,
        ]);
        $photos->options()->createMany([
            ['value' => 'yes', 'label' => 'Yes', 'position' => 0],
            ['value' => 'no', 'label' => 'No', 'position' => 1],
        ]);
        // Minors are always "no" and it's never asked in earnest — shown to
        // adults only.
        $group = $photos->conditionGroups()->create(['operator' => BooleanOperator::And->value]);
        $group->conditions()->create([
            'subject' => ConditionSubject::GuestType->value,
            'operator' => ConditionOperator::Equals->value,
            'value' => 'adult',
        ]);

        $user = $this->makeUser();
        Draft::factory()->create(['user_id' => $user->id, 'answers' => [Question::GUEST_TRIGGER_KEY => 'Yes']]);
        $this->actingAs($user);

        // A minor's submitted "yes" is dropped, same as any other hidden field.
        $this->post(route('registration.register.guests.store'), [
            'guest_type' => 'minor', 'guestname' => 'Kid', 'photos' => 'yes',
        ])->assertRedirect(route('registration.register.guests'));

        // An adult's own answer is kept.
        $this->post(route('registration.register.guests.store'), [
            'guest_type' => 'adult', 'guestname' => 'Grownup', 'photos' => 'yes',
        ])->assertRedirect(route('registration.register.guests'));

        $draft = Draft::where('user_id', $user->id)->first();
        $this->assertArrayNotHasKey('photos', $draft->guests[0]['answers']);
        $this->assertSame('yes', $draft->guests[1]['answers']['photos']);

        // The edit-form render agrees: hidden on the minor's page, shown on the adult's.
        $minorPage = $this->get(route('registration.register.guests.edit', $draft->guests[0]['id']))->assertOk();
        $this->assertMatchesRegularExpression('/data-question="photos"[^>]*\bhidden\b/', $minorPage->getContent());

        $adultPage = $this->get(route('registration.register.guests.edit', $draft->guests[1]['id']))->assertOk();
        $this->assertDoesNotMatchRegularExpression('/data-question="photos"[^>]*\bhidden\b/', $adultPage->getContent());
    }

    #[TestDox('guest routes require authentication')]
    public function test_guest_routes_require_authentication(): void
    {
        Route::get('/login', fn () => 'login')->name('login');

        $this->post(route('registration.register.guests.store'), [])->assertRedirect(route('login'));
    }
}
