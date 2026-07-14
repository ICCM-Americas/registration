<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Models\Draft;
use ConferenceTools\Registration\Models\GroupInvite;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Support\TestDraft;
use ConferenceTools\Registration\Tests\Concerns\BuildsRegistrationData;
use ConferenceTools\Registration\Tests\Concerns\WalksRegistrationWizard;
use ConferenceTools\Registration\Tests\Fixtures\QuestionConfigSeeder;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * The admin test drive's Group Member hub — mirrors GroupMemberControllerTest,
 * but against the session-held TestDraft: nothing is ever persisted, and the
 * session round-trip must carry the drafted members across requests (see
 * TestDraft::fromSession()/save()). No invite is ever emailed for a test run.
 */
#[TestDox('Test Group Member Controller')]
class TestGroupMemberControllerTest extends TestCase
{
    use BuildsRegistrationData, RefreshDatabase, WalksRegistrationWizard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allowRegistrationManagement();
    }

    /** Drive the walker over the admin test-drive routes instead of the real flow. */
    protected function wizardRoutes(): array
    {
        return [
            'step' => 'registration.admin.test',
            'store' => 'registration.admin.test.store',
            'committed' => 'registration.admin.dashboard',
        ];
    }

    /** Seed the session run as if the trigger step were already submitted "Yes". */
    private function startTriggeredRun(): void
    {
        session([TestDraft::SESSION_KEY => ['answers' => [Question::GROUP_TRIGGER_KEY => 'Yes'], 'guests' => [], 'group_members' => []]]);
    }

    #[TestDox('hub 404s until the trigger is answered yes')]
    public function test_hub_404s_until_the_trigger_is_answered_yes(): void
    {
        session([TestDraft::SESSION_KEY => ['answers' => [], 'guests' => [], 'group_members' => []]]);

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.test.group_members'))
            ->assertNotFound();
    }

    #[TestDox('add a group member persists nothing but survives the session round trip')]
    public function test_add_a_group_member_persists_nothing_but_survives_the_session_round_trip(): void
    {
        $this->actingAs($this->makeUser());
        $this->startTriggeredRun();

        $this->post(route('registration.admin.test.group_members.store'), [
            'group_member_name' => 'Session Member', 'group_member_email' => 'session@example.com',
        ])->assertRedirect(route('registration.admin.test.group_members'));

        // Nothing hit the database.
        $this->assertSame(0, GroupInvite::count());

        // But the session round-trip carried it: a fresh request still sees it.
        $this->get(route('registration.admin.test.group_members'))
            ->assertOk()
            ->assertSee('Session Member');

        $draft = TestDraft::fromSession();
        $this->assertCount(1, $draft->group_members);
        $this->assertSame('Session Member', $draft->group_members[0]['answers']['group_member_name']);
    }

    #[TestDox('edit and remove a group member')]
    public function test_edit_and_remove_a_group_member(): void
    {
        $this->actingAs($this->makeUser());
        $this->startTriggeredRun();

        $this->post(route('registration.admin.test.group_members.store'), [
            'group_member_name' => 'Kid', 'group_member_email' => 'kid@example.com',
        ]);
        $memberId = TestDraft::fromSession()->group_members[0]['id'];

        $this->get(route('registration.admin.test.group_members.edit', $memberId))
            ->assertOk()
            ->assertSee('value="Kid"', false);

        $this->post(route('registration.admin.test.group_members.update', $memberId), [
            'group_member_name' => 'Kid Updated', 'group_member_email' => 'kid@example.com',
        ])->assertRedirect(route('registration.admin.test.group_members'));
        $this->assertSame('Kid Updated', TestDraft::fromSession()->group_members[0]['answers']['group_member_name']);

        $this->delete(route('registration.admin.test.group_members.destroy', $memberId))
            ->assertRedirect(route('registration.admin.test.group_members'));
        $this->assertSame([], TestDraft::fromSession()->group_members);
    }

    #[TestDox('completing the run through the hub never creates a group invite')]
    public function test_completing_the_run_through_the_hub_never_creates_a_group_invite(): void
    {
        $this->defaultCurrency();
        $this->seed(QuestionConfigSeeder::class);
        $admin = $this->makeUser();

        $this->completeWizard($admin, array_merge($this->fixtureAnswers(), [Question::GROUP_TRIGGER_KEY => 'Yes']))
            ->assertRedirect(route('registration.admin.test.group_members'));

        $this->post(route('registration.admin.test.group_members.store'), [
            'group_member_name' => 'Never Saved', 'group_member_email' => 'never@example.com',
        ])->assertRedirect(route('registration.admin.test.group_members'));
        $this->assertCount(1, TestDraft::fromSession()->group_members);

        $this->completeWizard($admin, array_merge($this->fixtureAnswers(), [Question::GROUP_TRIGGER_KEY => 'Yes']))
            ->assertRedirect(route('registration.admin.dashboard'));

        // Nothing was recorded at all — no draft, and, in particular, no GroupInvite row.
        $this->assertSame(0, Draft::count());
        $this->assertSame(0, GroupInvite::count());
        $this->assertFalse(session()->has(TestDraft::SESSION_KEY));
    }
}
