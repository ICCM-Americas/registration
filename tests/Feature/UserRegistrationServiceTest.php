<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Models\Group;
use ConferenceTools\Registration\Services\UserRegistrationService;
use ConferenceTools\Registration\Tests\Concerns\BuildsRegistrationData;
use ConferenceTools\Registration\Tests\Fixtures\User;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * The registration service only writes the structural columns the package owns
 * and links the registrant to their group — the registrant's answers are
 * validated by the questionnaire and stored separately (see EavSubmissionTest).
 */
#[TestDox('User Registration Service')]
class UserRegistrationServiceTest extends TestCase
{
    use BuildsRegistrationData, RefreshDatabase;

    /** The service under test, resolved from the container. */
    private function service(): UserRegistrationService
    {
        return app(UserRegistrationService::class);
    }

    #[TestDox('register user sets structural fields and links the group')]
    public function test_register_user_sets_structural_fields_and_links_the_group(): void
    {
        $group = Group::factory()->create();

        // The account already exists (created by the host's authentication); the
        // package registers it without touching its email, password, or name.
        $account = $this->makeUser(['email' => 'grace@example.com', 'name' => 'Grace', 'mail_id' => null]);
        $originalPassword = $account->password;

        $user = $this->service()->registerUser(['organization' => 'Engines'], $group, $account, true);

        $this->assertInstanceOf(User::class, $user);
        $this->assertTrue((bool) $user->is_group_admin);
        $this->assertNotEmpty($user->mail_id);
        // The account's credentials and name are left exactly as the host set them.
        $this->assertSame('grace@example.com', $user->email);
        $this->assertSame('Grace', $user->name);
        $this->assertSame($originalPassword, $user->password);
        $this->assertSame($group->id, $user->group_id);
    }

    #[TestDox('register user preserves an existing mail id')]
    public function test_register_user_preserves_an_existing_mail_id(): void
    {
        $group = Group::factory()->create();
        $account = $this->makeUser(['mail_id' => 'keep-me']);

        $user = $this->service()->registerUser(['organization' => 'Engines'], $group, $account, false);

        $this->assertFalse((bool) $user->is_group_admin);
        $this->assertSame('keep-me', $user->mail_id);
    }

    /**
     * The account's name is host-owned (required, never blank) — registration
     * must never write to it, even if the questionnaire happens to have a
     * "name"-keyed answer (a coincidence, not a reserved key).
     */
    #[TestDox('register user never writes the questionnaires name answer to the account')]
    public function test_register_user_never_writes_the_questionnaires_name_answer_to_the_account(): void
    {
        $group = Group::factory()->create();
        $account = $this->makeUser(['name' => 'Original Name']);

        $user = $this->service()->registerUser(['name' => 'Someone Else'], $group, $account, false);

        $this->assertSame('Original Name', $user->name);
    }
}
