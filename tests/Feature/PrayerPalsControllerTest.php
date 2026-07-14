<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Enums\BadgeElementType;
use ConferenceTools\Registration\Enums\Gender;
use ConferenceTools\Registration\Enums\GuestType;
use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Models\BadgeLayoutElement;
use ConferenceTools\Registration\Models\Guest;
use ConferenceTools\Registration\Models\PrayerPalsAssignment;
use ConferenceTools\Registration\Models\PrayerPalsGroup;
use ConferenceTools\Registration\Models\Room;
use ConferenceTools\Registration\Models\Setting;
use ConferenceTools\Registration\Services\GuestQuestions;
use ConferenceTools\Registration\Tests\Concerns\BuildsReportData;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/** Feature tests for Prayer Pals Controller. */
#[TestDox('Prayer Pals Controller')]
class PrayerPalsControllerTest extends TestCase
{
    use BuildsReportData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allowRegistrationManagement();
        $this->seedReportQuestions();
    }

    /** The page routes for the data provider. */
    public static function pageRoutes(): array
    {
        return [
            'index' => ['get', 'registration.admin.logistics.prayer_pals', []],
            'settings' => ['put', 'registration.admin.logistics.prayer_pals.settings', []],
            'groups.store' => ['post', 'registration.admin.logistics.prayer_pals.groups.store', []],
        ];
    }

    #[DataProvider('pageRoutes')]
    #[TestDox('prayer pals routes require the gate')]
    public function test_prayer_pals_routes_require_the_gate(string $method, string $route, array $params): void
    {
        $this->denyRegistrationManagement();

        $this->actingAs($this->makeUser())
            ->$method(route($route, $params))
            ->assertForbidden();
    }

    #[TestDox('index splits registrants by sex and lists the unassigned')]
    public function test_index_splits_registrants_by_sex_and_lists_the_unassigned(): void
    {
        $this->makeRegistrant('Alan', 'Turing', ['gender' => 'm']);
        $this->makeRegistrant('Grace', 'Hopper', ['gender' => 'f']);

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.logistics.prayer_pals'))
            ->assertOk()
            ->assertSee('Alan Turing')
            ->assertSee('Grace Hopper');
    }

    /** The organization is shown alongside each name, so admins can avoid clustering people from the same one. */
    #[TestDox('index shows each occupants organization')]
    public function test_index_shows_each_occupants_organization(): void
    {
        $group = $this->reportGroup();
        $this->storeAnswers($group, QuestionScope::Group, ['organization' => 'Bletchley Park']);
        $host = $this->makeRegistrant('Alan', 'Turing', ['gender' => 'm'], $group);
        $this->makeGuest($host, GuestType::Adult, ['guestname' => 'Joan Clarke', 'guestgender' => 'f', 'guestprayerpals' => 'yes']);

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.logistics.prayer_pals'))
            ->assertOk()
            ->assertSeeInOrder(['Alan Turing', 'Bletchley Park'])
            // The guest has no organization answer of their own, so it falls back to the host's group.
            ->assertSeeInOrder(['Joan Clarke', 'Bletchley Park']);
    }

    #[TestDox('index shows the full name, not the badge name')]
    public function test_index_shows_the_full_name_not_the_badge_name(): void
    {
        $host = $this->makeRegistrant('Alan', 'Turing', ['gender' => 'm', 'badgename' => 'Prof. T']);
        app(GuestQuestions::class)->update([GuestQuestions::BADGE_NAME_KEY => 'guestbadgename']);
        $this->makeGuest($host, GuestType::Adult, ['guestname' => 'Formal Guest', 'guestbadgename' => 'Nickname', 'guestgender' => 'f', 'guestprayerpals' => 'yes']);

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.logistics.prayer_pals'))
            ->assertOk()
            ->assertSee('Alan Turing')
            ->assertDontSee('Prof. T')
            ->assertSee('Formal Guest')
            ->assertDontSee('Nickname');
    }

    #[TestDox('index flags registrants with no recorded sex')]
    public function test_index_flags_registrants_with_no_recorded_sex(): void
    {
        $this->makeRegistrant('Ambiguous', 'Person', ['gender' => 'unknown']);

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.logistics.prayer_pals'))
            ->assertOk()
            ->assertSee('Ambiguous Person');
    }

    #[TestDox('store group appends the next position per sex')]
    public function test_store_group_appends_the_next_position_per_sex(): void
    {
        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.logistics.prayer_pals.groups.store'), ['sex' => 'm'])
            ->assertRedirect(route('registration.admin.logistics.prayer_pals'));
        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.logistics.prayer_pals.groups.store'), ['sex' => 'm'])
            ->assertRedirect();
        // A different sex starts its own sequence at 1.
        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.logistics.prayer_pals.groups.store'), ['sex' => 'f'])
            ->assertRedirect();

        $this->assertSame([1, 2], PrayerPalsGroup::where('sex', 'm')->orderBy('position')->pluck('position')->all());
        $this->assertSame([1], PrayerPalsGroup::where('sex', 'f')->orderBy('position')->pluck('position')->all());
    }

    #[TestDox('store group rejects an invalid sex')]
    public function test_store_group_rejects_an_invalid_sex(): void
    {
        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.logistics.prayer_pals.groups.store'), ['sex' => 'x'])
            ->assertSessionHasErrors('sex');
    }

    #[TestDox('group label follows the chosen style')]
    public function test_group_label_follows_the_chosen_style(): void
    {
        $group = PrayerPalsGroup::factory()->create(['sex' => Gender::Male, 'position' => 3]);

        Setting::put(PrayerPalsGroup::LABEL_STYLE_SETTING, PrayerPalsGroup::LABEL_STYLE_NUMBER);
        $this->assertSame('3', $group->fresh()->label());

        Setting::put(PrayerPalsGroup::LABEL_STYLE_SETTING, PrayerPalsGroup::LABEL_STYLE_LETTER);
        $this->assertSame('C', $group->fresh()->label());
    }

    #[TestDox('assign places and moves a registrant')]
    public function test_assign_places_and_moves_a_registrant(): void
    {
        $groupA = PrayerPalsGroup::factory()->create(['sex' => Gender::Male, 'position' => 1]);
        $groupB = PrayerPalsGroup::factory()->create(['sex' => Gender::Male, 'position' => 2]);
        $user = $this->makeRegistrant('Alan', 'Turing', ['gender' => 'm']);

        $this->actingAs($this->makeUser())
            ->postJson(route('registration.admin.logistics.prayer_pals.assign'), [
                'occupant_type' => 'user', 'occupant_id' => $user->getKey(), 'prayer_pals_group_id' => $groupA->id,
            ])->assertOk()->assertJson(['status' => 'ok']);

        $this->assertSame($groupA->id, PrayerPalsAssignment::where('assignable_type', $user->getMorphClass())->where('assignable_id', $user->getKey())->value('prayer_pals_group_id'));

        // Assigning again moves rather than duplicating.
        $this->actingAs($this->makeUser())
            ->postJson(route('registration.admin.logistics.prayer_pals.assign'), [
                'occupant_type' => 'user', 'occupant_id' => $user->getKey(), 'prayer_pals_group_id' => $groupB->id,
            ])->assertOk();

        $this->assertSame(1, PrayerPalsAssignment::count());
        $this->assertSame($groupB->id, PrayerPalsAssignment::where('assignable_type', $user->getMorphClass())->where('assignable_id', $user->getKey())->value('prayer_pals_group_id'));
    }

    #[TestDox('assign places an opted in adult guest')]
    public function test_assign_places_an_opted_in_adult_guest(): void
    {
        $group = PrayerPalsGroup::factory()->create(['sex' => Gender::Female, 'position' => 1]);
        $host = $this->makeRegistrant('Alan', 'Turing', ['gender' => 'm']);
        $guest = $this->makeGuest($host, GuestType::Adult, ['guestgender' => 'f', 'guestprayerpals' => 'yes']);

        $this->actingAs($this->makeUser())
            ->postJson(route('registration.admin.logistics.prayer_pals.assign'), [
                'occupant_type' => 'guest', 'occupant_id' => $guest->getKey(), 'prayer_pals_group_id' => $group->id,
            ])->assertOk()->assertJson(['status' => 'ok']);

        $this->assertSame($group->id, PrayerPalsAssignment::where('assignable_type', Guest::class)->where('assignable_id', $guest->getKey())->value('prayer_pals_group_id'));
    }

    #[TestDox('assign rejects a guest who has not opted in')]
    public function test_assign_rejects_a_guest_who_has_not_opted_in(): void
    {
        $group = PrayerPalsGroup::factory()->create(['sex' => Gender::Female, 'position' => 1]);
        $host = $this->makeRegistrant('Alan', 'Turing', ['gender' => 'm']);
        $guest = $this->makeGuest($host, GuestType::Adult, ['guestgender' => 'f']); // no opt-in answer

        $this->actingAs($this->makeUser())
            ->postJson(route('registration.admin.logistics.prayer_pals.assign'), [
                'occupant_type' => 'guest', 'occupant_id' => $guest->getKey(), 'prayer_pals_group_id' => $group->id,
            ])->assertUnprocessable()->assertJsonValidationErrors('occupant_id');

        $this->assertSame(0, PrayerPalsAssignment::count());
    }

    #[TestDox('assign rejects a sex mismatch')]
    public function test_assign_rejects_a_sex_mismatch(): void
    {
        $group = PrayerPalsGroup::factory()->create(['sex' => Gender::Male, 'position' => 1]);
        $user = $this->makeRegistrant('Grace', 'Hopper', ['gender' => 'f']);

        $this->actingAs($this->makeUser())
            ->postJson(route('registration.admin.logistics.prayer_pals.assign'), [
                'occupant_type' => 'user', 'occupant_id' => $user->getKey(), 'prayer_pals_group_id' => $group->id,
            ])->assertUnprocessable()->assertJsonValidationErrors('occupant_id');

        $this->assertSame(0, PrayerPalsAssignment::count());
    }

    #[TestDox('assign rejects non registrants and unknown groups')]
    public function test_assign_rejects_non_registrants_and_unknown_groups(): void
    {
        $group = PrayerPalsGroup::factory()->create(['sex' => Gender::Male, 'position' => 1]);
        $notRegistered = $this->makeUser();

        $this->actingAs($this->makeUser())
            ->postJson(route('registration.admin.logistics.prayer_pals.assign'), [
                'occupant_type' => 'user', 'occupant_id' => $notRegistered->id, 'prayer_pals_group_id' => $group->id,
            ])->assertUnprocessable()->assertJsonValidationErrors('occupant_id');

        $registrant = $this->makeRegistrant('Alan', 'Turing', ['gender' => 'm']);

        $this->actingAs($this->makeUser())
            ->postJson(route('registration.admin.logistics.prayer_pals.assign'), [
                'occupant_type' => 'user', 'occupant_id' => $registrant->getKey(), 'prayer_pals_group_id' => $group->id + 999,
            ])->assertUnprocessable()->assertJsonValidationErrors('prayer_pals_group_id');
    }

    #[TestDox('unassign removes the placement')]
    public function test_unassign_removes_the_placement(): void
    {
        $group = PrayerPalsGroup::factory()->create(['sex' => Gender::Male, 'position' => 1]);
        $user = $this->makeRegistrant('Alan', 'Turing', ['gender' => 'm']);
        $assignment = PrayerPalsAssignment::create(['prayer_pals_group_id' => $group->id, 'assignable_type' => $user->getMorphClass(), 'assignable_id' => $user->getKey()]);

        $this->actingAs($this->makeUser())
            ->delete(route('registration.admin.logistics.prayer_pals.unassign', $assignment))
            ->assertRedirect(route('registration.admin.logistics.prayer_pals'));

        $this->assertSame(0, PrayerPalsAssignment::count());
    }

    #[TestDox('destroy group scatters members and closes the position gap')]
    public function test_destroy_group_scatters_members_and_closes_the_position_gap(): void
    {
        $groupA = PrayerPalsGroup::factory()->create(['sex' => Gender::Male, 'position' => 1]);
        $groupB = PrayerPalsGroup::factory()->create(['sex' => Gender::Male, 'position' => 2]);
        $groupC = PrayerPalsGroup::factory()->create(['sex' => Gender::Male, 'position' => 3]);
        $user = $this->makeRegistrant('Alan', 'Turing', ['gender' => 'm']);
        PrayerPalsAssignment::create(['prayer_pals_group_id' => $groupB->id, 'assignable_type' => $user->getMorphClass(), 'assignable_id' => $user->getKey()]);

        $this->actingAs($this->makeUser())
            ->delete(route('registration.admin.logistics.prayer_pals.groups.destroy', $groupB))
            ->assertRedirect(route('registration.admin.logistics.prayer_pals'));

        $this->assertSame(0, PrayerPalsAssignment::count());
        $this->assertSame(0, PrayerPalsGroup::where('id', $groupB->id)->count());
        // Group C's position closes the gap groupB's deletion left.
        $this->assertSame(2, $groupC->fresh()->position);
        $this->assertSame(1, $groupA->fresh()->position);
    }

    #[TestDox('index includes opted in adult guests but never minors')]
    public function test_index_includes_opted_in_adult_guests_but_never_minors(): void
    {
        $host = $this->makeRegistrant('Alan', 'Turing', ['gender' => 'm']);
        $this->makeGuest($host, GuestType::Adult, ['guestname' => 'Opted In Adult', 'guestgender' => 'f', 'guestprayerpals' => 'yes']);
        $this->makeGuest($host, GuestType::Adult, ['guestname' => 'Opted Out Adult', 'guestgender' => 'f', 'guestprayerpals' => 'no']);
        $this->makeGuest($host, GuestType::Minor, ['guestname' => 'Opted In Minor', 'guestgender' => 'f', 'guestprayerpals' => 'yes']);

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.logistics.prayer_pals'))
            ->assertOk()
            ->assertSee('Opted In Adult')
            ->assertDontSee('Opted Out Adult')
            // A minor is never included, even if they somehow "opted in".
            ->assertDontSee('Opted In Minor');
    }

    #[TestDox('a guest in prayer pals still appears in room assignment')]
    public function test_a_guest_in_prayer_pals_still_appears_in_room_assignment(): void
    {
        Room::factory()->create();
        $host = $this->makeRegistrant('Alan', 'Turing', ['gender' => 'm']);
        $guest = $this->makeGuest($host, GuestType::Adult, ['guestname' => 'Dual Purpose Guest', 'guestgender' => 'f', 'guestprayerpals' => 'yes']);

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.logistics.prayer_pals'))
            ->assertOk()
            ->assertSee('Dual Purpose Guest');

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.rooms.assignments'))
            ->assertOk()
            ->assertSee('Dual Purpose Guest');
    }

    #[TestDox('update settings saves the label style')]
    public function test_update_settings_saves_the_label_style(): void
    {
        $this->actingAs($this->makeUser())
            ->put(route('registration.admin.logistics.prayer_pals.settings'), ['label_style' => 'letter'])
            ->assertRedirect(route('registration.admin.logistics.prayer_pals'));

        $this->assertSame('letter', Setting::get(PrayerPalsGroup::LABEL_STYLE_SETTING));
    }

    #[TestDox('update settings rejects an invalid style')]
    public function test_update_settings_rejects_an_invalid_style(): void
    {
        $this->actingAs($this->makeUser())
            ->put(route('registration.admin.logistics.prayer_pals.settings'), ['label_style' => 'roman-numerals'])
            ->assertSessionHasErrors('label_style');
    }

    #[TestDox('badges show the prayer pals group label once added to the layout')]
    public function test_badges_show_the_prayer_pals_group_label_once_added_to_the_layout(): void
    {
        $user = $this->makeRegistrant('Ada', 'Lovelace', ['gender' => 'f', 'badgename' => 'Ada L.']);
        $group = PrayerPalsGroup::factory()->create(['sex' => Gender::Female, 'position' => 2]);
        PrayerPalsAssignment::create(['prayer_pals_group_id' => $group->id, 'assignable_type' => $user->getMorphClass(), 'assignable_id' => $user->getKey()]);

        BadgeLayoutElement::factory()
            ->ofType(BadgeElementType::BadgeName)
            ->create();
        BadgeLayoutElement::factory()
            ->ofType(BadgeElementType::PrayerPalsGroup)
            ->create();

        $content = $this->actingAs($this->makeUser())
            ->get(route('registration.admin.logistics.badges'))
            ->assertOk()
            ->assertSee('Ada L.')
            ->getContent();

        $this->assertMatchesRegularExpression('/>\s*2\s*<\/div>/', $content);
    }
}
