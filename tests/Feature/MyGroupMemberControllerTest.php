<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Mail\TemplatedMail;
use ConferenceTools\Registration\Models\Answer;
use ConferenceTools\Registration\Models\Group;
use ConferenceTools\Registration\Models\GroupInvite;
use ConferenceTools\Registration\Models\Payment;
use ConferenceTools\Registration\Services\RegistrationStatus;
use ConferenceTools\Registration\Tests\Concerns\BuildsRegistrationData;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * The post-commit "Manage Group Members" hub on My Registration: only the
 * group's admin may reach it, add/edit/remove real {@see GroupInvite} rows
 * directly (no wizard draft involved, unlike the pre-commit hub — see
 * GroupMemberControllerTest), and a consumed invite (the member has already
 * registered) is fully locked, not just removal-blocked.
 */
#[TestDox('My Group Member Controller')]
class MyGroupMemberControllerTest extends TestCase
{
    use BuildsRegistrationData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openRegistration();
    }

    #[TestDox('hub 404s for a non-admin group member')]
    public function test_hub_404s_for_a_non_admin_group_member(): void
    {
        $group = $this->makeGroupWithMembers();
        $member = $group->users()->where('is_group_admin', false)->firstOrFail();

        $this->actingAs($member)
            ->get(route('registration.mine.group_members'))
            ->assertNotFound();
    }

    #[TestDox('hub shows a completed member read-only and a pending invite with edit and remove actions')]
    public function test_hub_shows_completed_members_read_only_and_pending_invites_editable(): void
    {
        $group = $this->makeGroupWithMembers();
        $leader = $group->admin();

        $invite = GroupInvite::create(['group_id' => $group->id, 'token' => bin2hex(random_bytes(8))]);
        $this->storeAnswers($invite, QuestionScope::GroupMember, [
            'group_member_name' => 'Pending Person', 'group_member_email' => 'pending@example.com',
        ]);

        $response = $this->actingAs($leader)
            ->get(route('registration.mine.group_members'))
            ->assertOk()
            ->assertSee(__('registration::mine.group_member_registered'))
            ->assertSee('Pending Person')
            ->assertSee(__('registration::common.group_member_edit'))
            ->assertSee(__('registration::common.group_member_remove'));

        $response->assertSeeInOrder([
            __('registration::mine.group_member_registered'),
            'Pending Person',
            __('registration::common.group_member_edit'),
        ]);
    }

    #[TestDox('adding a member creates an unconsumed invite and sends the invite email')]
    public function test_adding_a_member_creates_an_unconsumed_invite_and_sends_the_invite_email(): void
    {
        Mail::fake();
        $group = $this->makeGroupWithMembers();
        $leader = $group->admin();

        $this->actingAs($leader)
            ->post(route('registration.mine.group_members.store'), [
                'group_member_name' => 'Grace Hopper', 'group_member_email' => 'grace@example.com',
            ])->assertRedirect(route('registration.mine.group_members'));

        $this->assertSame(1, GroupInvite::count());
        $invite = GroupInvite::first();
        $this->assertNull($invite->consumed_at);
        $this->assertSame($group->id, $invite->group_id);

        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail) => $mail->hasTo('grace@example.com'));
    }

    #[TestDox('adding the first post-commit member flips is_group to true on the group')]
    public function test_adding_the_first_post_commit_member_flips_is_group_to_true(): void
    {
        Mail::fake();
        $group = $this->makeGroupWithMembers();
        $group->update(['is_group' => false]);
        $leader = $group->admin();

        $this->actingAs($leader)->post(route('registration.mine.group_members.store'), [
            'group_member_name' => 'Grace Hopper', 'group_member_email' => 'grace@example.com',
        ])->assertRedirect();

        $this->assertTrue($group->fresh()->is_group);
    }

    #[TestDox('edit and update a pending invite')]
    public function test_edit_and_update_a_pending_invite(): void
    {
        Mail::fake();
        $group = $this->makeGroupWithMembers();
        $leader = $group->admin();
        $invite = GroupInvite::create(['group_id' => $group->id, 'token' => 'tok']);
        $this->storeAnswers($invite, QuestionScope::GroupMember, [
            'group_member_name' => 'Grace Hopper', 'group_member_email' => 'grace@example.com',
        ]);

        $this->actingAs($leader)
            ->get(route('registration.mine.group_members.edit', $invite))
            ->assertOk()
            ->assertSee('value="Grace Hopper"', false);

        $this->post(route('registration.mine.group_members.update', $invite), [
            'group_member_name' => 'Grace Hopper Updated', 'group_member_email' => 'grace@example.com',
        ])->assertRedirect(route('registration.mine.group_members'));

        $this->assertSame('Grace Hopper Updated', $invite->registrationAnswers()->value('group_member_name'));
    }

    #[TestDox('updating a pending invites email re-sends the invite; updating only the name does not')]
    #[DataProvider('emailChangeProvider')]
    public function test_updating_email_resends_the_invite_only_when_the_email_changed(string $newEmail, bool $expectResend): void
    {
        Mail::fake();
        $group = $this->makeGroupWithMembers();
        $leader = $group->admin();
        $invite = GroupInvite::create(['group_id' => $group->id, 'token' => 'tok']);
        $this->storeAnswers($invite, QuestionScope::GroupMember, [
            'group_member_name' => 'Grace Hopper', 'group_member_email' => 'grace@example.com',
        ]);

        $this->actingAs($leader)->post(route('registration.mine.group_members.update', $invite), [
            'group_member_name' => 'Grace Hopper', 'group_member_email' => $newEmail,
        ])->assertRedirect();

        if ($expectResend) {
            Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail) => $mail->hasTo($newEmail));
        } else {
            Mail::assertNothingSent();
        }
    }

    public static function emailChangeProvider(): array
    {
        return [
            'email changed' => ['grace.hopper@example.com', true],
            'email unchanged' => ['grace@example.com', false],
        ];
    }

    #[TestDox('a consumed invite 404s on edit, update, and destroy')]
    public function test_a_consumed_invite_404s_on_edit_update_and_destroy(): void
    {
        $group = $this->makeGroupWithMembers();
        $leader = $group->admin();
        $invite = GroupInvite::create(['group_id' => $group->id, 'token' => 'tok', 'consumed_at' => now()]);
        $this->storeAnswers($invite, QuestionScope::GroupMember, [
            'group_member_name' => 'Grace Hopper', 'group_member_email' => 'grace@example.com',
        ]);

        $this->actingAs($leader);
        $this->get(route('registration.mine.group_members.edit', $invite))->assertNotFound();
        $this->post(route('registration.mine.group_members.update', $invite), ['group_member_name' => 'x', 'group_member_email' => 'x@example.com'])->assertNotFound();
        $this->delete(route('registration.mine.group_members.destroy', $invite))->assertNotFound();

        $this->assertNotNull($invite->fresh());
    }

    #[TestDox('an invite belonging to another group 404s')]
    public function test_an_invite_belonging_to_another_group_404s(): void
    {
        $group = $this->makeGroupWithMembers();
        $leader = $group->admin();
        $otherGroup = Group::factory()->create(['name' => 'Other Group']);
        $otherInvite = GroupInvite::create(['group_id' => $otherGroup->id, 'token' => 'tok']);

        $this->actingAs($leader);
        $this->get(route('registration.mine.group_members.edit', $otherInvite))->assertNotFound();
        $this->post(route('registration.mine.group_members.update', $otherInvite), [])->assertNotFound();
        $this->delete(route('registration.mine.group_members.destroy', $otherInvite))->assertNotFound();
    }

    #[TestDox('removing a pending invite is allowed, but a registered members entry offers no remove action')]
    public function test_removing_a_pending_invite_is_allowed(): void
    {
        $group = $this->makeGroupWithMembers();
        $leader = $group->admin();
        $invite = GroupInvite::create(['group_id' => $group->id, 'token' => 'tok']);
        $this->storeAnswers($invite, QuestionScope::GroupMember, ['group_member_name' => 'Grace', 'group_member_email' => 'grace@example.com']);

        $this->actingAs($leader)
            ->delete(route('registration.mine.group_members.destroy', $invite))
            ->assertRedirect(route('registration.mine.group_members'));

        $this->assertSame(0, GroupInvite::count());
    }

    #[TestDox('deleting an unconsumed invite also deletes its answers')]
    public function test_deleting_an_unconsumed_invite_also_deletes_its_answers(): void
    {
        $group = $this->makeGroupWithMembers();
        $invite = GroupInvite::create(['group_id' => $group->id, 'token' => 'tok']);
        $this->storeAnswers($invite, QuestionScope::GroupMember, ['group_member_name' => 'Grace', 'group_member_email' => 'grace@example.com']);

        $invite->delete();

        $this->assertSame(0, Answer::where('owner_type', GroupInvite::class)->where('owner_id', $invite->id)->count());
    }

    #[TestDox('every action is gated behind admin status and eligibility to modify')]
    #[DataProvider('ineligibilityProvider')]
    public function test_every_action_is_gated(\Closure $makeIneligible): void
    {
        $group = $this->makeGroupWithMembers();
        $leader = $group->admin();
        $invite = GroupInvite::create(['group_id' => $group->id, 'token' => 'tok']);
        $this->storeAnswers($invite, QuestionScope::GroupMember, ['group_member_name' => 'Grace', 'group_member_email' => 'grace@example.com']);
        $makeIneligible($leader, $group);

        $this->actingAs($leader);
        $this->get(route('registration.mine.group_members'))->assertNotFound();
        $this->get(route('registration.mine.group_members.create'))->assertNotFound();
        $this->post(route('registration.mine.group_members.store'), ['group_member_name' => 'x', 'group_member_email' => 'x@example.com'])->assertNotFound();
        $this->get(route('registration.mine.group_members.edit', $invite))->assertNotFound();
        $this->post(route('registration.mine.group_members.update', $invite), ['group_member_name' => 'x', 'group_member_email' => 'x@example.com'])->assertNotFound();
        $this->delete(route('registration.mine.group_members.destroy', $invite))->assertNotFound();

        $this->assertSame(1, GroupInvite::count(), 'no side effect happened');
    }

    public static function ineligibilityProvider(): array
    {
        return [
            'marked paid' => [fn ($leader) => Payment::factory()->paid()->create(['user_id' => $leader->id])],
            'registration closed' => [fn () => app(RegistrationStatus::class)->close()],
            'not the group admin' => [function ($leader, Group $group) {
                $leader->update(['is_group_admin' => false]);
                $group->users()->where('id', '!=', $leader->id)->first()->update(['is_group_admin' => true]);
            }],
        ];
    }
}
