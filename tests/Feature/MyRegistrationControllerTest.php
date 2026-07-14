<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Models\Draft;
use ConferenceTools\Registration\Models\Group;
use ConferenceTools\Registration\Models\GroupInvite;
use ConferenceTools\Registration\Models\Guest;
use ConferenceTools\Registration\Models\Payment;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Services\RegistrationStatus;
use ConferenceTools\Registration\Tests\Concerns\BuildsRegistrationData;
use ConferenceTools\Registration\Tests\Concerns\WalksRegistrationWizard;
use ConferenceTools\Registration\Tests\Fixtures\QuestionConfigSeeder;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * The registrant-facing "My Registration" page: viewing an already-committed
 * registration, the group leader's member list, and modifying it by
 * reseeding the wizard from the committed answers.
 */
#[TestDox('My Registration Controller')]
class MyRegistrationControllerTest extends TestCase
{
    use BuildsRegistrationData, RefreshDatabase, WalksRegistrationWizard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openRegistration();
    }

    /** Seed the questionnaire these tests submit against (mirrors RegistrationControllerTest). */
    private function seedForm(): void
    {
        $this->defaultCurrency();
        $this->seed(QuestionConfigSeeder::class);
    }

    #[TestDox('view page 404s for a user who has not registered')]
    public function test_view_page_404s_for_a_user_who_has_not_registered(): void
    {
        $this->actingAs($this->makeUser())
            ->get(route('registration.mine'))
            ->assertNotFound();
    }

    #[TestDox('view page shows the registrants own answers and cost summary')]
    public function test_view_page_shows_the_registrants_own_answers_and_cost_summary(): void
    {
        $group = $this->makeGroupWithMembers();

        $this->actingAs($group->admin())
            ->get(route('registration.mine'))
            ->assertOk()
            ->assertSee(__('registration::admin.payments_answers_heading'))
            ->assertSee(__('registration::common.cost_summary_heading'));
    }

    #[TestDox('a plain member sees no group section')]
    public function test_a_plain_member_sees_no_group_section(): void
    {
        $group = $this->makeGroupWithMembers();
        $member = $group->users()->where('is_group_admin', false)->firstOrFail();

        $this->actingAs($member)
            ->get(route('registration.mine'))
            ->assertOk()
            ->assertDontSee(__('registration::mine.group_members_heading'));
    }

    #[TestDox('a group leader sees a completed member as registered and a pending invite as not yet registered')]
    public function test_a_group_leader_sees_completed_members_and_pending_invites(): void
    {
        $group = $this->makeGroupWithMembers();
        $leader = $group->admin();

        $invite = GroupInvite::create(['group_id' => $group->id, 'token' => bin2hex(random_bytes(8))]);
        $this->storeAnswers($invite, QuestionScope::GroupMember, [
            'group_member_name' => 'Pending Person', 'group_member_email' => 'pending@example.com',
        ]);

        $response = $this->actingAs($leader)
            ->get(route('registration.mine'))
            ->assertOk()
            ->assertSee(__('registration::mine.group_members_heading'))
            ->assertSee('Pending Person')
            ->assertSee(__('registration::mine.group_member_registered'))
            ->assertSee(__('registration::mine.group_member_not_registered'));

        // Exactly one completed entry (the other member, not the leader
        // themselves) and one pending entry.
        $response->assertSeeInOrder([
            __('registration::mine.group_member_registered'),
            'Pending Person',
            __('registration::mine.group_member_not_registered'),
        ]);
    }

    #[TestDox('modify is offered while open and unpaid')]
    public function test_modify_is_offered_while_open_and_unpaid(): void
    {
        $group = $this->makeGroupWithMembers();

        $this->actingAs($group->admin())
            ->get(route('registration.mine'))
            ->assertSee(__('registration::mine.modify'));
    }

    #[TestDox('the manage guests and manage group members links are offered to a group admin while eligible')]
    public function test_manage_guests_and_manage_group_members_links_are_offered_to_a_group_admin(): void
    {
        $group = $this->makeGroupWithMembers();

        $this->actingAs($group->admin())
            ->get(route('registration.mine'))
            ->assertSee(__('registration::common.manage_guests_link'))
            ->assertSee(__('registration::common.manage_group_members_link'));
    }

    #[TestDox('the manage group members link is withheld from a plain (non-admin) group member')]
    public function test_manage_group_members_link_is_withheld_from_a_plain_member(): void
    {
        $group = $this->makeGroupWithMembers();
        $member = $group->users()->where('is_group_admin', false)->firstOrFail();

        $this->actingAs($member)->get(route('registration.mine'))
            ->assertSee(__('registration::common.manage_guests_link'))
            ->assertDontSee(__('registration::common.manage_group_members_link'));
    }

    #[TestDox('modify is replaced by the administrator contact once marked paid')]
    public function test_modify_is_replaced_by_the_administrator_contact_once_marked_paid(): void
    {
        $group = $this->makeGroupWithMembers();
        $leader = $group->admin();
        Payment::factory()->paid()->create(['user_id' => $leader->id]);

        $this->actingAs($leader)
            ->get(route('registration.mine'))
            ->assertDontSee(__('registration::mine.modify'))
            ->assertDontSee(__('registration::common.manage_guests_link'))
            ->assertDontSee(__('registration::common.manage_group_members_link'))
            ->assertSee(__('registration::mine.contact_admin_intro'))
            ->assertSee('mailto:admin@example.com', false);
    }

    #[TestDox('modify is replaced by the administrator contact once registration closes')]
    public function test_modify_is_replaced_by_the_administrator_contact_once_registration_closes(): void
    {
        $group = $this->makeGroupWithMembers();
        app(RegistrationStatus::class)->close();

        $this->actingAs($group->admin())
            ->get(route('registration.mine'))
            ->assertDontSee(__('registration::mine.modify'))
            ->assertDontSee(__('registration::common.manage_guests_link'))
            ->assertSee(__('registration::mine.contact_admin_intro'));
    }

    #[TestDox('the edit wizard is reseeded with the committed answers and excludes the guest/group steps')]
    public function test_the_edit_wizard_is_reseeded_with_the_committed_answers_and_excludes_the_guest_group_steps(): void
    {
        $this->seedForm();
        $leader = $this->makeUser();
        $this->completeWizard($leader, $this->fixtureAnswers())->assertRedirect(route('registration.info'));

        $this->actingAs($leader)
            ->get(route('registration.mine.edit'))
            ->assertOk()
            ->assertSee('value="Ada"', false)
            ->assertDontSee('name="'.Question::GUEST_TRIGGER_KEY.'"', false)
            ->assertDontSee('name="'.Question::GROUP_TRIGGER_KEY.'"', false);
    }

    #[TestDox('completing the edit wizard updates the existing registration in place')]
    public function test_completing_the_edit_wizard_updates_the_existing_registration_in_place(): void
    {
        $this->seedForm();
        $leader = $this->makeUser();
        $this->completeWizard($leader, $this->fixtureAnswers())->assertRedirect(route('registration.info'));

        $this->actingAs($leader);
        $this->completeEditWizard(array_merge($this->fixtureAnswers(), ['lastname' => 'Byron']))
            ->assertRedirect(route('registration.mine'));

        $leader->refresh();
        $this->assertSame('Byron', $leader->registrationAnswers()->value('lastname'));
        // No duplicate Group/Guest rows and no leftover draft.
        $this->assertSame(1, Group::count());
        $this->assertSame(0, Guest::count());
        $this->assertSame(0, GroupInvite::count());
        $this->assertTrue(Draft::where('user_id', $leader->id)->doesntExist());
    }

    #[TestDox('modify is refused server side once marked paid, even if the edit link is hit directly')]
    public function test_modify_is_refused_server_side_once_marked_paid(): void
    {
        $this->seedForm();
        $leader = $this->makeUser();
        $this->completeWizard($leader, $this->fixtureAnswers())->assertRedirect(route('registration.info'));
        Payment::factory()->paid()->create(['user_id' => $leader->id]);

        $this->actingAs($leader);
        $this->get(route('registration.mine.edit'))->assertRedirect(route('registration.mine'));
        $this->post(route('registration.mine.update'), ['_step' => 1, '_direction' => 'next'])
            ->assertRedirect(route('registration.mine'));

        // Untouched: still the original name.
        $this->assertSame('Lovelace', $leader->registrationAnswers()->value('lastname'));
    }

    #[TestDox('modify is refused server side once registration closes, even if the edit link is hit directly')]
    public function test_modify_is_refused_server_side_once_registration_closes(): void
    {
        $this->seedForm();
        $leader = $this->makeUser();
        $this->completeWizard($leader, $this->fixtureAnswers())->assertRedirect(route('registration.info'));
        app(RegistrationStatus::class)->close();

        $this->actingAs($leader);
        $this->get(route('registration.mine.edit'))->assertRedirect(route('registration.mine'));
    }

    /** The id (first question) of the step currently rendered by the edit wizard. */
    private function currentEditStep(): int
    {
        $html = $this->get(route('registration.mine.edit'))->assertOk()->getContent();
        preg_match('/name="_step" value="(\d+)"/', $html, $matches);

        return (int) ($matches[1] ?? 0);
    }

    /** Walk the edit wizard to completion. */
    private function completeEditWizard(array $answers, int $maxSteps = 12): TestResponse
    {
        $mineUrl = route('registration.mine');
        $editUrl = route('registration.mine.edit');
        $response = null;

        for ($step = 0; $step < $maxSteps; $step++) {
            $questionId = $this->currentEditStep();

            $response = $this->post(route('registration.mine.update'), array_merge($answers, [
                '_step' => $questionId,
                '_direction' => 'next',
            ]));

            if ($response->headers->get('Location') === $mineUrl) {
                return $response;
            }

            if ($response->headers->get('Location') !== $editUrl) {
                return $response;
            }
        }

        return $response;
    }
}
