<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Models\Group;
use ConferenceTools\Registration\Services\GroupRegistrationService;
use ConferenceTools\Registration\Tests\Concerns\BuildsRegistrationData;
use ConferenceTools\Registration\Tests\Fixtures\QuestionConfigSeeder;
use ConferenceTools\Registration\Tests\Fixtures\User;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;

/** Feature tests for Group Registration Service. */
#[TestDox('Group Registration Service')]
class GroupRegistrationServiceTest extends TestCase
{
    use BuildsRegistrationData, RefreshDatabase;

    /** The service under test, resolved from the container. */
    private function service(): GroupRegistrationService
    {
        return app(GroupRegistrationService::class);
    }

    #[TestDox('register group creates the group and links the admin')]
    public function test_register_group_creates_the_group_and_links_the_admin(): void
    {
        // The host application created and authenticated the account first.
        $account = $this->makeUser(['email' => 'ada@example.com']);

        $user = $this->service()->registerGroup([
            'name' => 'Ada', 'organization' => 'Analytical Engines',
        ], $account);

        $this->assertInstanceOf(User::class, $user);
        $this->assertSame($account->getKey(), $user->getKey());
        $this->assertSame('ada@example.com', $user->email);
        $this->assertTrue((bool) $user->is_group_admin);
        $this->assertSame(1, Group::count());
        $this->assertSame('Analytical Engines', $user->group->name);
    }

    #[TestDox('create group uses the organization as its name')]
    public function test_create_group_uses_the_organization_as_its_name(): void
    {
        $group = $this->service()->createGroup(['organization' => 'Steampunk Collective']);

        $this->assertSame('Steampunk Collective', $group->name);
    }

    /**
     * Group questions are hidden from the wizard, so a registration usually has
     * no organization answer — the group is then named for the registrant.
     */
    #[TestDox('create group falls back to the registrant name')]
    public function test_create_group_falls_back_to_the_registrant_name(): void
    {
        $group = $this->service()->createGroup(['name' => 'Ada', 'lastname' => 'Lovelace']);

        $this->assertSame('Ada Lovelace', $group->name);
    }

    /**
     * The group's registration details are now answers, read through accessors —
     * including org_type, which resolves the free-text value when "other".
     */
    #[TestDox('group details are read from the eav answers')]
    public function test_group_details_are_read_from_the_eav_answers(): void
    {
        $this->defaultCurrency();
        $this->seed(QuestionConfigSeeder::class);

        $group = Group::factory()->create(['name' => 'Engines']);
        $this->storeAnswers($group, QuestionScope::Group, [
            'organization' => 'Engines', 'orgtype' => 'other', 'orgtypeother' => 'Steampunk Collective',
            'website' => 'https://example.com', 'address' => '1 Babbage St', 'town' => 'London',
            'state' => 'Greater London', 'zipcode' => '00000', 'country' => 'UK', 'telephone' => '12345',
        ]);

        $this->assertSame('Steampunk Collective', $group->org_type);
        $this->assertSame('https://example.com', $group->website);
        $this->assertSame('Greater London', $group->state);
        $this->assertSame('London', $group->town);
    }
}
