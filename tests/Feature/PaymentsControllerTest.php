<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Enums\GuestType;
use ConferenceTools\Registration\Enums\PerDiemMode;
use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Enums\QuestionType;
use ConferenceTools\Registration\Models\BaseCharge;
use ConferenceTools\Registration\Models\DiscountCode;
use ConferenceTools\Registration\Models\Group;
use ConferenceTools\Registration\Models\Guest;
use ConferenceTools\Registration\Models\Payment;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\Section;
use ConferenceTools\Registration\Services\GuestQuestions;
use ConferenceTools\Registration\Services\PerDiem;
use ConferenceTools\Registration\Services\Registrants;
use ConferenceTools\Registration\Services\ReportQuestions;
use ConferenceTools\Registration\Tests\Concerns\BuildsRegistrationData;
use ConferenceTools\Registration\Tests\Fixtures\User;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * The admin Payments console: the registrant (+ guest) list with computed
 * cost and Group/Leader labels, the read-only view, and marking a registrant
 * paid. Editing answers and the cost-change preview are covered by
 * {@see PaymentAnswersControllerTest}.
 */
#[TestDox('Payments Controller')]
class PaymentsControllerTest extends TestCase
{
    use BuildsRegistrationData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allowRegistrationManagement();
        $this->seedQuestionConfig();
        $this->openRegistration();
    }

    #[TestDox('payments routes require the gate')]
    public function test_payments_routes_require_the_gate(): void
    {
        $this->denyRegistrationManagement();

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.payments'))
            ->assertForbidden();
    }

    #[TestDox('index shows the nominated name, not the badge name, with computed cost and nested guests')]
    public function test_index_shows_the_nominated_name_not_the_badge_name_with_computed_cost_and_nested_guests(): void
    {
        // Nominate the badge name to a question distinct from first/last name,
        // with a visibly different value — proves the list reads the
        // nominated first/last name, never the badge name.
        $this->nominateDistinctBadgeName();

        $registrant = $this->soloRegistrant(['name' => 'Wendy', 'lastname' => 'Young', 'nickname' => 'Badge Value']);
        $this->seedGuestScope();
        $guest = Guest::create(['user_id' => $registrant->id, 'type' => GuestType::Adult->value, 'position' => 0]);
        $this->storeAnswers($guest, QuestionScope::Guest, ['guestname' => 'Guest Wendy', 'extra' => 'addon']);

        $response = $this->actingAs($this->makeUser())->get(route('registration.admin.payments'));

        $response->assertOk();
        $response->assertSee('Wendy Young');
        $response->assertDontSee('Badge Value');
        $response->assertSee('Guest Wendy');
    }

    #[TestDox('index shows each guests own total, including their per-diem share, not just their own priced options')]
    public function test_index_shows_each_guests_own_total_including_their_per_diem_share_not_just_their_own_priced_options(): void
    {
        // Guests with no priced options of their own — the exact case that
        // used to show $0.00: their total previously came only from
        // Guest::cost() (stored priced-option cost), never their per-diem
        // share of the registrant's per-diem billing.
        app(PerDiem::class)->update(40.0, 30.0, 20.0, 2, PerDiemMode::AllDays);
        $registrant = $this->soloRegistrant(['name' => 'Wendy', 'lastname' => 'Young']);
        Guest::create(['user_id' => $registrant->id, 'type' => GuestType::Adult->value, 'position' => 0]);
        Guest::create(['user_id' => $registrant->id, 'type' => GuestType::Minor->value, 'position' => 1]);

        $html = $this->actingAs($this->makeUser())->get(route('registration.admin.payments'))->getContent();
        $card = $this->cardsByRegistrantName($html)['Wendy Young'];

        // Adult guest: 2 conference days × 30 = 60. Minor guest: 2 × 20 = 40.
        // Neither guest total is the previously-shown $0.00.
        $this->assertStringContainsString('Cost: $ 60.00', $card);
        $this->assertStringContainsString('Cost: $ 40.00', $card);
        $this->assertStringNotContainsString('Cost: $ 0.00', $card);
    }

    #[TestDox('index lists the registrants own cost breakdown, base charge and priced options, above the guest list, excluding guest costs')]
    public function test_index_lists_the_registrants_own_cost_breakdown_above_the_guest_list_excluding_guest_costs(): void
    {
        BaseCharge::factory()->create(['name' => 'Registration Fee', 'amount' => 50]);
        app(PerDiem::class)->update(40.0, 30.0, 20.0, 2, PerDiemMode::AllDays);
        $registrant = $this->soloRegistrant(['name' => 'Wendy', 'lastname' => 'Young']);
        Guest::create(['user_id' => $registrant->id, 'type' => GuestType::Adult->value, 'position' => 0]);

        $html = $this->actingAs($this->makeUser())->get(route('registration.admin.payments'))->getContent();
        $card = $this->cardsByRegistrantName($html)['Wendy Young'];

        // Registrant's own lines: base charge (50) + accommodation (100) +
        // attendee per-diem (2 × 40 = 80) — never the guest's own per-diem
        // share (2 × 30 = 60), which belongs with the guest's name instead.
        $this->assertStringContainsString('Registration Fee', $card);
        $this->assertStringContainsString('50.00', $card);
        $this->assertStringContainsString('Accommodation', $card);
        $this->assertStringContainsString('100.00', $card);
        $this->assertStringContainsString('80.00', $card);
        // The breakdown table sits above the guest list, not mixed into it.
        $this->assertLessThan(
            strpos($card, 'payments-guests-list'),
            strpos($card, 'payments-breakdown-table'),
        );
        // Grand total: 50 + 100 + 80 (registrant) + 60 (guest) = 290, still
        // the same figure the header's overall cost shows.
        $this->assertStringContainsString('290.00', $card);
    }

    #[TestDox('index includes a discount line in the registrants breakdown so the numbers still add up to the total')]
    public function test_index_includes_a_discount_line_in_the_registrants_breakdown_so_the_numbers_still_add_up_to_the_total(): void
    {
        $this->discountQuestion();
        DiscountCode::factory()->create(['code' => 'K7QND2', 'formula' => '-20']);
        $registrant = $this->soloRegistrant(['name' => 'Wendy', 'lastname' => 'Young', 'discount' => 'K7QND2']);

        $html = $this->actingAs($this->makeUser())->get(route('registration.admin.payments'))->getContent();
        $card = $this->cardsByRegistrantName($html)['Wendy Young'];

        // Accommodation (100) - discount (20) = 80.
        $this->assertStringContainsString('Discount', $card);
        $this->assertStringContainsString('-20.00', $card);
        $this->assertStringContainsString('80.00', $card);
    }

    #[TestDox('index only labels an actual group, and only its admin as Leader')]
    public function test_index_only_labels_an_actual_group_and_only_its_admin_as_leader(): void
    {
        $group = Group::factory()->create(['is_group' => true]);
        $this->storeAnswers($group, QuestionScope::Group, $this->fixtureAnswers());
        $leader = $this->makeUser(['is_group_admin' => true, 'group_id' => $group->id]);
        $this->storeAnswers($leader, QuestionScope::Participant, array_merge($this->fixtureAnswers(), ['name' => 'Leo', 'lastname' => 'Leader']));
        $member = $this->makeUser(['is_group_admin' => false, 'group_id' => $group->id]);
        $this->storeAnswers($member, QuestionScope::Participant, array_merge($this->fixtureAnswers(), ['name' => 'Mona', 'lastname' => 'Member']));

        // A solo registrant is still structurally "is_group_admin" (they
        // administer their own single-person group row), but is_group is
        // false — must show neither label.
        $this->soloRegistrant(['name' => 'Sam', 'lastname' => 'Solo']);

        $html = $this->actingAs($this->makeUser())->get(route('registration.admin.payments'))->getContent();
        $cards = $this->cardsByRegistrantName($html);

        $this->assertStringContainsString('Group', $cards['Leo Leader']);
        $this->assertStringContainsString('Leader', $cards['Leo Leader']);
        $this->assertStringContainsString('Group', $cards['Mona Member']);
        $this->assertStringNotContainsString('Leader', $cards['Mona Member']);
        $this->assertStringNotContainsString('Group', $cards['Sam Solo']);
        $this->assertStringNotContainsString('Leader', $cards['Sam Solo']);
    }

    #[TestDox('index sorts by last name, first name, or clusters group members with their leader')]
    public function test_index_sorts_by_last_name_first_name_or_clusters_group_members_with_their_leader(): void
    {
        // Two solo registrants alphabetically between the group's members by
        // name, so "group" sort (which clusters the group together) produces
        // a different order than plain last/first name sort would.
        $this->soloRegistrant(['name' => 'Alice', 'lastname' => 'Alpha']);
        $this->soloRegistrant(['name' => 'Oscar', 'lastname' => 'Omega']);

        $group = Group::factory()->create(['is_group' => true]);
        $this->storeAnswers($group, QuestionScope::Group, $this->fixtureAnswers());
        $leader = $this->makeUser(['is_group_admin' => true, 'group_id' => $group->id]);
        $this->storeAnswers($leader, QuestionScope::Participant, array_merge($this->fixtureAnswers(), ['name' => 'Marcus', 'lastname' => 'Delta']));
        $member = $this->makeUser(['is_group_admin' => false, 'group_id' => $group->id]);
        $this->storeAnswers($member, QuestionScope::Participant, array_merge($this->fixtureAnswers(), ['name' => 'Bella', 'lastname' => 'Foxtrot']));

        $admin = $this->makeUser();

        $lastOrder = $this->namesInOrder($this->actingAs($admin)->get(route('registration.admin.payments', ['sort' => 'last'])));
        $this->assertSame(['Alice Alpha', 'Marcus Delta', 'Bella Foxtrot', 'Oscar Omega'], $lastOrder);

        $firstOrder = $this->namesInOrder($this->actingAs($admin)->get(route('registration.admin.payments', ['sort' => 'first'])));
        $this->assertSame(['Alice Alpha', 'Bella Foxtrot', 'Marcus Delta', 'Oscar Omega'], $firstOrder);

        $groupOrder = $this->namesInOrder($this->actingAs($admin)->get(route('registration.admin.payments', ['sort' => 'group'])));
        // The group's leader sorts first within the group, then its member,
        // and the two solo registrants keep their own alphabetical position
        // around the clustered pair.
        $this->assertSame(['Alice Alpha', 'Marcus Delta', 'Bella Foxtrot', 'Oscar Omega'], $groupOrder);
    }

    #[TestDox('show renders the registrant and guest answers plus an itemized cost summary')]
    public function test_show_renders_the_registrant_and_guest_answers_plus_an_itemized_cost_summary(): void
    {
        $registrant = $this->soloRegistrant(['name' => 'Wendy', 'lastname' => 'Young']);
        $this->seedGuestScope();
        $guest = Guest::create(['user_id' => $registrant->id, 'type' => GuestType::Adult->value, 'position' => 0]);
        $this->storeAnswers($guest, QuestionScope::Guest, ['guestname' => 'Guest Wendy', 'extra' => 'addon']);

        $response = $this->actingAs($this->makeUser())->get(route('registration.admin.payments.show', $registrant->id));

        $response->assertOk();
        $response->assertSee('Wendy Young');
        $response->assertSee('Guest Wendy');
        // Cost summary: base "Hotel" accommodation (100) + guest's "addon" (15).
        $response->assertSee('Hotel');
        $response->assertSee('115.00');
    }

    #[TestDox('marking a registrant paid persists is_paid amount and notes')]
    public function test_marking_a_registrant_paid_persists_is_paid_amount_and_notes(): void
    {
        $registrant = $this->makeUser();
        $this->storeAnswers($registrant, QuestionScope::Participant, $this->fixtureAnswers());

        $this->actingAs($this->makeUser())
            ->put(route('registration.admin.payments.payment.update', $registrant->id), [
                'is_paid' => '1', 'amount' => '120.00', 'notes' => 'Paid by bank transfer.',
            ])
            ->assertRedirect();

        $payment = Payment::where('user_id', $registrant->id)->firstOrFail();
        $this->assertTrue($payment->is_paid);
        $this->assertSame('120.00', $payment->amount);
        $this->assertSame('Paid by bank transfer.', $payment->notes);
    }

    #[TestDox('re-saving payment without the paid checkbox clears is_paid')]
    public function test_re_saving_payment_without_the_paid_checkbox_clears_is_paid(): void
    {
        $registrant = $this->makeUser();
        $this->storeAnswers($registrant, QuestionScope::Participant, $this->fixtureAnswers());
        Payment::factory()->paid()->create(['user_id' => $registrant->id, 'amount' => 50]);

        $this->actingAs($this->makeUser())
            ->put(route('registration.admin.payments.payment.update', $registrant->id), ['amount' => '50']);

        $this->assertFalse(Payment::where('user_id', $registrant->id)->firstOrFail()->is_paid);
    }

    /**
     * A registrant who registered individually, not as part of a group — still
     * backed by their own (is_group: false) Group row per the current
     * architecture, so {@see Registrants::all()}
     * (which requires a group_id) lists them.
     */
    private function soloRegistrant(array $answers): User
    {
        $group = Group::factory()->create(['is_group' => false]);
        $user = $this->makeUser(['is_group_admin' => true, 'group_id' => $group->id]);
        $this->storeAnswers($user, QuestionScope::Participant, array_merge($this->fixtureAnswers(), $answers));

        return $user;
    }

    /** Nominate the badge name to a question distinct from first/last name, with a distinct value. */
    private function nominateDistinctBadgeName(): void
    {
        app(ReportQuestions::class)->update([ReportQuestions::BADGE_NAME_KEY => 'nickname']);
    }

    /** A Guest-scope name question plus a priced add-on, nominated for GuestQuestions::displayName(). */
    private function seedGuestScope(): void
    {
        if (Section::forScope(QuestionScope::Guest)->exists()) {
            return;
        }

        $section = Section::create([
            'scope' => QuestionScope::Guest->value, 'key' => 'guest-details', 'title' => 'Guest Details', 'position' => 0, 'enabled' => true,
        ]);
        Question::create([
            'section_id' => $section->id, 'key' => 'guestname', 'type' => QuestionType::Text->value,
            'label' => 'Guest name', 'position' => 0, 'required' => false, 'enabled' => true,
        ]);
        $extra = Question::create([
            'section_id' => $section->id, 'key' => 'extra', 'type' => QuestionType::Radio->value,
            'label' => 'Extra', 'position' => 1, 'required' => false, 'enabled' => true,
        ]);
        $extra->options()->create(['value' => 'none', 'label' => 'None', 'position' => 0]);
        $extra->options()->create(['value' => 'addon', 'label' => 'Add-on', 'cost' => 15, 'position' => 1]);

        app(GuestQuestions::class)->update(['guest_name_key' => 'guestname']);
    }

    /** A Participant-scope discount-code question, alongside the fixture questionnaire. */
    private function discountQuestion(): void
    {
        $section = Section::create([
            'scope' => QuestionScope::Participant->value, 'key' => 'billing-discount', 'title' => 'Discount', 'position' => 2, 'enabled' => true,
        ]);
        Question::create([
            'section_id' => $section->id, 'key' => 'discount', 'type' => QuestionType::DiscountCode->value,
            'label' => 'Discount code', 'position' => 0, 'required' => false, 'enabled' => true,
        ]);
    }

    /** The registrant names shown on the index page, in document order. */
    private function namesInOrder(TestResponse $response): array
    {
        preg_match_all('/<strong>([^<]+)<\/strong>/', $response->getContent(), $matches);

        return $matches[1];
    }

    /** Each registrant's own card fragment, keyed by their displayed name — so a badge assertion can't accidentally match a neighboring card. */
    private function cardsByRegistrantName(string $html): array
    {
        $cards = [];
        foreach (preg_split('/(?=<div class="card mb-3">)/', $html) as $card) {
            if (preg_match('/<strong>([^<]+)<\/strong>/', $card, $m)) {
                $cards[$m[1]] = $card;
            }
        }

        return $cards;
    }
}
