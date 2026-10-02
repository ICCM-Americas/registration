<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Enums\GuestType;
use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Enums\QuestionType;
use ConferenceTools\Registration\Models\Guest;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\Section;
use ConferenceTools\Registration\Tests\Concerns\BuildsRegistrationData;
use ConferenceTools\Registration\Tests\Fixtures\User;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Editing a registrant's or a guest's answers from the admin Payments
 * console, and the cost-change preview endpoint that backs its
 * before-you-change-it modal. The listing/payment-marking flows are covered
 * by {@see PaymentsControllerTest}.
 */
#[TestDox('Payment Answers Controller')]
class PaymentAnswersControllerTest extends TestCase
{
    use BuildsRegistrationData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allowRegistrationManagement();
        $this->seedQuestionConfig();
        $this->openRegistration();
    }

    #[TestDox('edit answers form renders the registrant\'s current answers')]
    public function test_edit_answers_form_renders_the_registrants_current_answers(): void
    {
        $registrant = $this->registrantWithAnswers([]);

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.payments.answers.edit', $registrant->id))
            ->assertOk()
            ->assertSee('value="Ada"', false);
    }

    #[TestDox('edit answers form carries the question a search result asked to highlight')]
    public function test_edit_answers_form_carries_the_question_to_highlight(): void
    {
        $registrant = $this->registrantWithAnswers([]);

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.payments.answers.edit', [$registrant->id, 'highlight' => 'lastname']))
            ->assertOk()
            ->assertSee('data-highlight="lastname"', false);
    }

    #[TestDox('saving an answer persists it through the real answer store')]
    public function test_saving_an_answer_persists_it_through_the_real_answer_store(): void
    {
        $registrant = $this->registrantWithAnswers([]);

        $this->actingAs($this->makeUser())
            ->put(route('registration.admin.payments.answers.update', $registrant->id), $this->participantPayload(['lastname' => 'Byron']))
            ->assertRedirect(route('registration.admin.payments.show', $registrant->id));

        $this->assertSame('Byron', $registrant->fresh()->registrationAnswers()->value('lastname'));
    }

    #[TestDox('saving a cost-affecting change updates the registrant\'s cost')]
    public function test_saving_a_cost_affecting_change_updates_the_registrants_cost(): void
    {
        $registrant = $this->registrantWithAnswers([]);
        $this->assertSame(100.0, $registrant->fresh()->cost());

        $this->actingAs($this->makeUser())
            ->put(route('registration.admin.payments.answers.update', $registrant->id), $this->participantPayload(['accommodation' => 'none']));

        $this->assertSame(0.0, $registrant->fresh()->cost());
    }

    #[TestDox('cost preview returns the recomputed total for a candidate priced-option change')]
    public function test_cost_preview_returns_the_recomputed_total_for_a_candidate_priced_option_change(): void
    {
        $registrant = $this->registrantWithAnswers([]);

        $response = $this->actingAs($this->makeUser())->postJson(
            route('registration.admin.payments.answers.preview', $registrant->id),
            $this->participantPayload(['product_dinner' => '1']),
        );

        $response->assertOk();
        // Unchanged "hotel" accommodation (100) plus the newly selected "dinner" product (20).
        $this->assertEqualsWithDelta(120.0, $response->json('total'), 0.001);
    }

    #[TestDox('cost preview responds with no content when the candidate answers do not validate')]
    public function test_cost_preview_responds_with_no_content_when_the_candidate_answers_do_not_validate(): void
    {
        $registrant = $this->registrantWithAnswers([]);
        $payload = $this->participantPayload([]);
        unset($payload['passport']); // a required field dropped mid-edit

        $this->actingAs($this->makeUser())
            ->postJson(route('registration.admin.payments.answers.preview', $registrant->id), $payload)
            ->assertNoContent();
    }

    #[TestDox('edit and save a guest\'s own answers')]
    public function test_edit_and_save_a_guests_own_answers(): void
    {
        $registrant = $this->registrantWithAnswers([]);
        $this->seedGuestScope();
        $guest = Guest::create(['user_id' => $registrant->id, 'type' => GuestType::Adult->value, 'position' => 0]);
        $this->storeAnswers($guest, QuestionScope::Guest, ['name' => 'Guest', 'extra' => 'none']);

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.payments.guests.answers.edit', [$registrant->id, $guest->id]))
            ->assertOk();

        $this->actingAs($this->makeUser())
            ->put(route('registration.admin.payments.guests.answers.update', [$registrant->id, $guest->id]), ['name' => 'Guest', 'extra' => 'addon'])
            ->assertRedirect(route('registration.admin.payments.show', $registrant->id));

        $this->assertSame(15.0, $guest->fresh()->cost());
    }

    #[TestDox('guest cost preview reflects the change on the owning registrant\'s total')]
    public function test_guest_cost_preview_reflects_the_change_on_the_owning_registrants_total(): void
    {
        $registrant = $this->registrantWithAnswers([]);
        $this->seedGuestScope();
        $guest = Guest::create(['user_id' => $registrant->id, 'type' => GuestType::Adult->value, 'position' => 0]);
        $this->storeAnswers($guest, QuestionScope::Guest, ['name' => 'Guest', 'extra' => 'none']);

        $response = $this->actingAs($this->makeUser())->postJson(
            route('registration.admin.payments.guests.answers.preview', [$registrant->id, $guest->id]),
            ['name' => 'Guest', 'extra' => 'addon'],
        );

        $response->assertOk();
        // The registrant's own "hotel" accommodation (100) plus the guest's newly selected add-on (15).
        $this->assertEqualsWithDelta(115.0, $response->json('total'), 0.001);
    }

    #[TestDox('a guest route 404s when the guest does not belong to the given registrant')]
    public function test_a_guest_route_404s_when_the_guest_does_not_belong_to_the_given_registrant(): void
    {
        $registrant = $this->registrantWithAnswers([]);
        $other = $this->registrantWithAnswers([]);
        $this->seedGuestScope();
        $guest = Guest::create(['user_id' => $other->id, 'type' => GuestType::Adult->value, 'position' => 0]);

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.payments.guests.answers.edit', [$registrant->id, $guest->id]))
            ->assertNotFound();
    }

    /** A registrant with the fixture questionnaire's valid answers (accommodation: hotel, cost 100), optionally overridden. */
    private function registrantWithAnswers(array $overrides): User
    {
        $user = $this->makeUser();
        $this->storeAnswers($user, QuestionScope::Participant, array_merge($this->fixtureAnswers(), $overrides));

        return $user;
    }

    /** A raw form submission (the shape the browser posts, not the normalized answer map) for the Participant-scope form. */
    private function participantPayload(array $overrides): array
    {
        return array_merge([
            'name' => 'Ada', 'lastname' => 'Lovelace', 'passport' => 'Ada Lovelace',
            'gender' => 'f', 'residence' => 'UK', 'accommodation' => 'hotel',
            Question::GUEST_TRIGGER_KEY => 'No', Question::GROUP_TRIGGER_KEY => 'No',
        ], $overrides);
    }

    /** A Guest-scope name question plus a priced add-on. */
    private function seedGuestScope(): void
    {
        if (Section::forScope(QuestionScope::Guest)->exists()) {
            return;
        }

        $section = Section::create([
            'scope' => QuestionScope::Guest->value, 'key' => 'guest-details', 'title' => 'Guest Details', 'position' => 0, 'enabled' => true,
        ]);
        Question::create([
            'section_id' => $section->id, 'key' => 'name', 'type' => QuestionType::Text->value,
            'label' => 'Guest name', 'position' => 0, 'required' => false, 'enabled' => true,
        ]);
        $extra = Question::create([
            'section_id' => $section->id, 'key' => 'extra', 'type' => QuestionType::Radio->value,
            'label' => 'Extra', 'position' => 1, 'required' => false, 'enabled' => true,
        ]);
        $extra->options()->create(['value' => 'none', 'label' => 'None', 'position' => 0]);
        $extra->options()->create(['value' => 'addon', 'label' => 'Add-on', 'cost' => 15, 'position' => 1]);
    }
}
