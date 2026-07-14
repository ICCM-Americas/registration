<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Enums\GuestType;
use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Models\Guest;
use ConferenceTools\Registration\Services\GuestQuestions;
use ConferenceTools\Registration\Services\RegistrationArchiver;
use ConferenceTools\Registration\Services\RegistrationSnapshotIdentities;
use ConferenceTools\Registration\Tests\Concerns\BuildsReportData;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Resolving who everyone in a registration snapshot is: registrant and guest
 * identities (badge name, email, organization, entry type), built from a
 * freshly gathered {@see RegistrationArchiver::snapshot()} the same way it
 * would be from a decoded archive.
 */
#[TestDox('Registration Snapshot Identities')]
class RegistrationSnapshotIdentitiesTest extends TestCase
{
    use BuildsReportData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedReportQuestions();
    }

    private function identities(): RegistrationSnapshotIdentities
    {
        return app(RegistrationSnapshotIdentities::class);
    }

    private function registrantIdentity(Model $user): ?array
    {
        $snapshot = app(RegistrationArchiver::class)->snapshot();

        return $this->identities()->resolve($snapshot)->get($this->identities()->ownerKey(get_class($user), $user->id));
    }

    private function guestIdentity(Guest $guest): ?array
    {
        $snapshot = app(RegistrationArchiver::class)->snapshot();

        return $this->identities()->resolve($snapshot)->get($this->identities()->ownerKey(Guest::class, $guest->id));
    }

    #[TestDox('a registrant resolves to their badge name, account email, and the attendee entry type')]
    public function test_a_registrant_resolves_to_their_badge_name_account_email_and_the_attendee_entry_type(): void
    {
        $host = $this->makeRegistrant('Ada', 'Lovelace');

        $identity = $this->registrantIdentity($host);

        $this->assertSame('Ada Lovelace', $identity['name']);
        $this->assertSame($host->email, $identity['email']);
        $this->assertSame(__('registration::admin.report_entry_attendee'), $identity['entryType']);
    }

    #[TestDox('a registrant with no organization answer of their own falls back to their group name')]
    public function test_a_registrant_with_no_organization_answer_of_their_own_falls_back_to_their_group_name(): void
    {
        $host = $this->makeRegistrant('Ada', 'Lovelace');

        $this->assertSame('Analytical Engines', $this->registrantIdentity($host)['organization']);
    }

    #[TestDox('a registrant prefers their groups own organization answer over the bare group name')]
    public function test_a_registrant_prefers_their_groups_own_organization_answer_over_the_bare_group_name(): void
    {
        $host = $this->makeRegistrant('Ada', 'Lovelace');
        $this->storeAnswers($host->group, QuestionScope::Group, ['organization' => 'Engines Ltd']);

        $this->assertSame('Engines Ltd', $this->registrantIdentity($host)['organization']);
    }

    #[TestDox('a guest falls back to their plain display name while no badge name is nominated, and inherits their registrants organization')]
    public function test_a_guest_falls_back_to_their_plain_display_name_and_inherits_their_registrants_organization(): void
    {
        $host = $this->makeRegistrant('Ada', 'Lovelace');
        $this->storeAnswers($host->group, QuestionScope::Group, ['organization' => 'Engines Ltd']);
        $guest = $this->makeGuest($host, GuestType::Adult, ['guestname' => 'Charles Babbage']);

        $identity = $this->guestIdentity($guest);

        $this->assertSame('Charles Babbage', $identity['name']);
        $this->assertNull($identity['email']);
        $this->assertSame('Engines Ltd', $identity['organization']);
    }

    #[TestDox('a guest prefers their nominated badge name over the plain display name')]
    public function test_a_guest_prefers_their_nominated_badge_name_over_the_plain_display_name(): void
    {
        app(GuestQuestions::class)->update([GuestQuestions::BADGE_NAME_KEY => 'guestbadgename']);
        $host = $this->makeRegistrant('Ada', 'Lovelace');
        $guest = $this->makeGuest($host, GuestType::Adult, ['guestname' => 'Plain Name', 'guestbadgename' => 'Badge Name']);

        $this->assertSame('Badge Name', $this->guestIdentity($guest)['name']);
    }

    #[DataProvider('guestTypes')]
    #[TestDox('a guest resolves to the entry type matching their guest type')]
    public function test_a_guest_resolves_to_the_entry_type_matching_their_guest_type(GuestType $type, string $expectedKey): void
    {
        $host = $this->makeRegistrant('Ada', 'Lovelace');
        $guest = $this->makeGuest($host, $type, ['guestname' => 'Guest Name']);

        $this->assertSame(__($expectedKey), $this->guestIdentity($guest)['entryType']);
    }

    /** The guest types and the entry-type key each should resolve to. */
    public static function guestTypes(): array
    {
        return [
            'adult' => [GuestType::Adult, 'registration::admin.report_entry_adult_guest'],
            'minor' => [GuestType::Minor, 'registration::admin.report_entry_minor_guest'],
        ];
    }
}
