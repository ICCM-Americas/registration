<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Models\Draft;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Tests\Concerns\BuildsRegistrationData;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * The Group Member hub and add/edit/remove flow, addressed against the
 * registrant's Draft directly (no need to walk the whole wizard — see
 * RegistrationControllerTest for the full end-to-end commit path). The
 * group-member-details questions are the seeded system questions, live from
 * the migration, so unlike GuestControllerTest there's no fixture to seed.
 */
#[TestDox('Group Member Controller')]
class GroupMemberControllerTest extends TestCase
{
    use BuildsRegistrationData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openRegistration();
    }

    #[TestDox('hub 404s until the trigger is answered yes')]
    public function test_hub_404s_until_the_trigger_is_answered_yes(): void
    {
        $user = $this->makeUser();
        Draft::factory()->create(['user_id' => $user->id, 'answers' => []]);

        $this->actingAs($user)->get(route('registration.register.group_members'))->assertNotFound();
    }

    #[TestDox('create and store 404 until the trigger is answered yes')]
    public function test_create_and_store_404_until_the_trigger_is_answered_yes(): void
    {
        $user = $this->makeUser();
        Draft::factory()->create(['user_id' => $user->id, 'answers' => []]);
        $this->actingAs($user);

        $this->get(route('registration.register.group_members.create'))->assertNotFound();
        $this->post(route('registration.register.group_members.store'), [])->assertNotFound();
    }

    #[TestDox('add edit and remove a group member')]
    public function test_add_edit_and_remove_a_group_member(): void
    {
        $user = $this->makeUser();
        Draft::factory()->create(['user_id' => $user->id, 'answers' => [Question::GROUP_TRIGGER_KEY => 'Yes']]);
        $this->actingAs($user);

        $this->get(route('registration.register.group_members'))
            ->assertOk()
            ->assertSee(__('registration::common.group_member_list_empty'));

        $this->get(route('registration.register.group_members.create'))
            ->assertOk()
            ->assertSee('name="group_member_name"', false)
            ->assertSee('name="group_member_email"', false);

        $this->post(route('registration.register.group_members.store'), [
            'group_member_name' => 'Grace Hopper', 'group_member_email' => 'grace@example.com',
        ])->assertRedirect(route('registration.register.group_members'));

        $draft = Draft::where('user_id', $user->id)->first();
        $this->assertCount(1, $draft->group_members);
        $memberId = $draft->group_members[0]['id'];
        $this->assertSame([
            'group_member_name' => 'Grace Hopper', 'group_member_email' => 'grace@example.com',
        ], $draft->group_members[0]['answers']);

        $this->get(route('registration.register.group_members'))
            ->assertOk()
            ->assertSee('Grace Hopper')
            ->assertSee('grace@example.com');

        $this->get(route('registration.register.group_members.edit', $memberId))
            ->assertOk()
            ->assertSee('value="Grace Hopper"', false);

        $this->post(route('registration.register.group_members.update', $memberId), [
            'group_member_name' => 'Grace Hopper Updated', 'group_member_email' => 'grace@example.com',
        ])->assertRedirect(route('registration.register.group_members'));

        $draft->refresh();
        $this->assertSame('Grace Hopper Updated', $draft->group_members[0]['answers']['group_member_name']);

        $this->delete(route('registration.register.group_members.destroy', $memberId))
            ->assertRedirect(route('registration.register.group_members'));

        $this->assertSame([], $draft->fresh()->group_members);
    }

    #[TestDox('name and email are required')]
    public function test_name_and_email_are_required(): void
    {
        $user = $this->makeUser();
        Draft::factory()->create(['user_id' => $user->id, 'answers' => [Question::GROUP_TRIGGER_KEY => 'Yes']]);
        $this->actingAs($user);

        $this->post(route('registration.register.group_members.store'), [])
            ->assertSessionHasErrors(['group_member_name', 'group_member_email']);

        $this->assertSame(0, count(Draft::where('user_id', $user->id)->first()->group_members ?? []));
    }

    #[TestDox('edit destroy and update 404 for an unknown member')]
    public function test_edit_destroy_and_update_404_for_an_unknown_member(): void
    {
        $user = $this->makeUser();
        Draft::factory()->create(['user_id' => $user->id, 'answers' => [Question::GROUP_TRIGGER_KEY => 'Yes']]);
        $this->actingAs($user);

        $this->get(route('registration.register.group_members.edit', 'not-a-real-id'))->assertNotFound();
        $this->post(route('registration.register.group_members.update', 'not-a-real-id'), [])->assertNotFound();
        $this->delete(route('registration.register.group_members.destroy', 'not-a-real-id'))->assertNotFound();
    }

    #[TestDox('group member routes require authentication')]
    public function test_group_member_routes_require_authentication(): void
    {
        Route::get('/login', fn () => 'login')->name('login');

        $this->post(route('registration.register.group_members.store'), [])->assertRedirect(route('login'));
    }
}
