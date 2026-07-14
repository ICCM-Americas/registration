<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Http\Controllers\GroupInviteController;
use ConferenceTools\Registration\Models\Group;
use ConferenceTools\Registration\Models\GroupInvite;
use ConferenceTools\Registration\Tests\Concerns\BuildsRegistrationData;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Landing an invited group member from their email link: the token is the
 * link's sole credential (see GroupInvite's own doc comment) — this
 * controller only remembers it in the session and hands off to the host's
 * login/signup.
 */
#[TestDox('Group Invite Controller')]
class GroupInviteControllerTest extends TestCase
{
    use BuildsRegistrationData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->openRegistration();
        Route::get('/login', fn () => 'login')->name('login');
    }

    #[TestDox('a valid unconsumed token stores the session key and redirects to login')]
    public function test_a_valid_unconsumed_token_stores_the_session_key_and_redirects_to_login(): void
    {
        $group = Group::factory()->create();
        $invite = GroupInvite::create(['group_id' => $group->id, 'token' => 'abc123']);

        $this->get(route('registration.register.invite.accept', $invite->token))
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', __('registration::common.invite_login_notice'));

        $this->assertSame('abc123', session(GroupInviteController::SESSION_KEY));
    }

    #[TestDox('an unknown token 404s')]
    public function test_an_unknown_token_404s(): void
    {
        $this->get(route('registration.register.invite.accept', 'not-a-real-token'))->assertNotFound();
    }

    #[TestDox('an already consumed token 404s')]
    public function test_an_already_consumed_token_404s(): void
    {
        $group = Group::factory()->create();
        $invite = GroupInvite::create([
            'group_id' => $group->id, 'token' => 'used123', 'consumed_at' => now(),
        ]);

        $this->get(route('registration.register.invite.accept', $invite->token))->assertNotFound();
    }
}
