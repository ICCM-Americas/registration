<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Models\Answer;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Tests\Concerns\BuildsRegistrationData;
use ConferenceTools\Registration\Tests\Concerns\WalksRegistrationWizard;
use ConferenceTools\Registration\Tests\Fixtures\QuestionConfigSeeder;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Walking the server-side wizard validates each step on the backend and persists
 * the configured answers to the EAV store on commit.
 */
#[TestDox('Eav Submission')]
class EavSubmissionTest extends TestCase
{
    use BuildsRegistrationData, RefreshDatabase, WalksRegistrationWizard;

    protected function setUp(): void
    {
        parent::setUp();

        // The registrant-facing routes are gated on the registration window.
        $this->openRegistration();
    }

    /** Seed the questionnaire these tests submit against. */
    private function seedForm(): void
    {
        $this->defaultCurrency();
        $this->seed(QuestionConfigSeeder::class);
    }

    /** One stored answer's value for the owner. */
    private function answerValue($owner, string $key): ?Answer
    {
        return Answer::whereHas('question', fn ($q) => $q->where('key', $key))
            ->where('owner_type', $owner->getMorphClass())
            ->where('owner_id', $owner->getKey())
            ->first();
    }

    #[TestDox('registration stores participant answers and no group answers')]
    public function test_registration_stores_participant_answers_and_no_group_answers(): void
    {
        $this->seedForm();
        $account = $this->makeUser(['email' => 'ada@example.com', 'name' => 'Account Name']);

        // Group-scope fields are posted anyway (a tampered client could); the
        // wizard never asks them, so they must not be stored.
        $this->completeWizard($account, [
            'name' => 'Ada', 'lastname' => 'Lovelace', 'passport' => 'Ada Lovelace',
            'gender' => 'f', 'residence' => 'UK',
            'organization' => 'Engines', 'orgtype' => 'business',
            'address' => '1 St', 'town' => 'London', 'zipcode' => '00000',
            'country' => 'UK', 'telephone' => '12345',
            'accommodation' => 'hotel',
            'product_dinner' => 'on',
            Question::GUEST_TRIGGER_KEY => 'No',
            Question::GROUP_TRIGGER_KEY => 'No',
        ])->assertRedirect(route('registration.info'));

        $account->refresh();

        // Participant-scope answers stored against the user, including the priced
        // accommodation choice (its value + its snapshotted cost) and the
        // product checkbox.
        $this->assertSame('Ada', $this->answerValue($account, 'name')->value);
        $accommodationAnswer = $this->answerValue($account, 'accommodation');
        $this->assertSame('hotel', $accommodationAnswer->value);
        $this->assertEqualsWithDelta(100.0, (float) $accommodationAnswer->cost, 0.001);

        $productAnswer = $this->answerValue($account, 'products');
        $this->assertNotNull($productAnswer);
        $this->assertSame('dinner', $productAnswer->value);

        // Group questions are hidden from the flow, so nothing lands on the
        // group — it is named for the registrant instead of an organization.
        $group = $account->group;
        $this->assertNull($this->answerValue($group, 'organization'));
        $this->assertNull($this->answerValue($group, 'orgtype'));
        $this->assertSame('Ada Lovelace', $group->name);

        // Cost is read from the EAV answers (hotel 100 + product 20). The
        // account's own name is host-owned; registration never writes to it.
        $this->assertSame('Account Name', $account->name);
        $this->assertEqualsWithDelta(120.0, $account->cost(), 0.001);
    }

    #[TestDox('invalid step is rejected server side and stores nothing')]
    public function test_invalid_step_is_rejected_server_side_and_stores_nothing(): void
    {
        $this->seedForm();
        $account = $this->makeUser();
        $this->actingAs($account);

        // First step requires name/lastname/… — submitting it empty is rejected on
        // the server, regardless of the client.
        $this->post(route('registration.register.store'), [
            '_step' => $this->currentWizardStep(),
            '_direction' => 'next',
            'name' => '',
        ])->assertSessionHasErrors('name');

        $this->assertSame(0, Answer::count());
    }
}
