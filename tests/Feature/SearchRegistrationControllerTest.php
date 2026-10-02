<?php

namespace ConferenceTools\Registration\Tests\Feature;

use Closure;
use ConferenceTools\Registration\Enums\GuestType;
use ConferenceTools\Registration\Models\Answer;
use ConferenceTools\Registration\Models\Group;
use ConferenceTools\Registration\Models\Guest;
use ConferenceTools\Registration\Tests\Concerns\BuildsSearchData;
use ConferenceTools\Registration\Tests\Fixtures\User;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Deleting the registrations behind the admin search's answer hits: the
 * confirmation dialog's contents and the deletion it submits.
 */
#[TestDox('Search Registration Controller')]
class SearchRegistrationControllerTest extends TestCase
{
    use BuildsSearchData, RefreshDatabase;

    /** @var array<string, mixed> */
    private array $data;

    private User $leader;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allowRegistrationManagement();
        $this->data = $this->seedSearchData();
        $group = $this->makeGroupWithMembers();
        $this->leader = $group->admin();
        $this->member = $group->users()->where('is_group_admin', false)->first();
    }

    #[TestDox('requires the gate')]
    public function test_requires_the_gate(): void
    {
        $this->denyRegistrationManagement();

        $this->actingAs($this->makeUser())->get(route('registration.admin.search.registrations.preview'))->assertForbidden();
        $this->actingAs($this->makeUser())->deleteJson(route('registration.admin.search.registrations.destroy'))->assertForbidden();
    }

    #[TestDox('the dialog lists each registration with its guests, asks about each guest, and asks a leader\'s successor')]
    public function test_the_dialog_lists_registrations_guests_and_successors(): void
    {
        $ada = $this->data['registrant'];

        $this->preview(['user:'.$this->leader->id, 'guest:'.$ada->id.':'.$this->data['guest']->id])
            ->assertOk()
            ->assertSee('name="registrants[]" value="'.$this->leader->id.'"', false)
            ->assertSee($this->memberName($this->leader).'</strong>', false)
            ->assertSee('('.trans_choice('registration::admin.search_delete_guest_count', 0).')', false)
            ->assertSee('name="leaders['.$this->leader->id.']"', false)
            ->assertSee('<option value="'.$this->member->id.'">'.$this->memberName($this->member).'</option>', false)
            ->assertSee('name="guest_targets[]" value="guest:'.$ada->id.':'.$this->data['guest']->id.'"', false)
            ->assertSee(__('registration::admin.search_delete_only_guest', [
                'guest' => __('registration::admin.search_guest_named', ['type' => __('registration::common.guest_type_adult'), 'name' => 'alphaguest']),
                'name' => 'Ada Lovelace',
            ]), false)
            ->assertSee(trans_choice('registration::admin.search_delete_whole', 1, ['name' => 'Ada Lovelace']));
    }

    #[TestDox('the dialog folds a guest into its registrant\'s selected registration, once')]
    public function test_the_dialog_folds_a_guest_into_its_registrants_registration(): void
    {
        $ada = $this->data['registrant'];

        $content = $this->preview(['user:'.$ada->id, 'guest:'.$ada->id.':'.$this->data['guest']->id, 'user:'.$ada->id])
            ->assertOk()
            ->assertDontSee('guest_targets[]', false)
            ->getContent();

        $this->assertSame(1, substr_count($content, 'name="registrants[]"'));
    }

    #[TestDox('the dialog notes when a leader\'s group would be left empty')]
    public function test_the_dialog_notes_when_a_leaders_group_would_be_left_empty(): void
    {
        $this->preview(['user:'.$this->leader->id, 'user:'.$this->member->id])
            ->assertSee(__('registration::admin.search_delete_group_removed'))
            ->assertDontSee('name="leaders[', false);
    }

    #[TestDox('"select all matching" covers every answer hit of the search, drafts included')]
    public function test_select_all_matching_covers_every_answer_hit(): void
    {
        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.search.registrations.preview', [
                'all' => 1, 'q' => 'alpha(answer|dguest)', 'regex' => 1, 'in' => ['answers', 'drafts'],
            ]))
            ->assertOk()
            ->assertSee('name="registrants[]" value="'.$this->data['registrant']->id.'"', false)
            ->assertSee('name="guest_targets[]" value="draft-guest:'.$this->data['drafter']->id.':dg-1"', false)
            ->assertSee(trans_choice('registration::admin.search_delete_whole', 1, ['name' => 'drafter@example.com']));
    }

    /** Search input "select all matching" must refuse. */
    public static function badSearches(): array
    {
        return [
            'nothing to search checked' => [['q' => 'alpha']],
            'invalid regular expression' => [['q' => '(alpha', 'regex' => 1, 'in' => ['answers']]],
        ];
    }

    #[DataProvider('badSearches')]
    #[TestDox('"select all matching" refuses a search with $_dataName')]
    public function test_select_all_matching_refuses_a_bad_search(array $query): void
    {
        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.search.registrations.preview', ['all' => 1] + $query))
            ->assertStatus(422);
    }

    #[TestDox('the dialog says so when nothing selected still exists')]
    public function test_the_dialog_says_so_when_nothing_selected_still_exists(): void
    {
        $ada = $this->data['registrant']->id;

        $this->preview(['bogus', ['nested'], 'user:9999', 'guest:9999:1', 'guest:'.$ada.':9999', 'draft-guest:'.$ada.':x', 'draft-guest:'.$this->data['drafter']->id.':x'])
            ->assertOk()
            ->assertSee(__('registration::admin.search_delete_nothing'))
            ->assertDontSee('<form', false);
    }

    /** Incomplete deletion payloads and the field each is refused on. */
    public static function refusedDeletions(): array
    {
        return [
            'a guest without a choice' => [fn (self $t): array => ['guest_targets' => [$t->guestTarget()]], 'guests'],
            'a leader without a successor' => [fn (self $t): array => ['registrants' => [$t->leader->id]], 'leaders'],
            'a successor outside the group' => [fn (self $t): array => ['registrants' => [$t->leader->id], 'leaders' => [$t->leader->id => $t->data['registrant']->id]], 'leaders'],
        ];
    }

    #[DataProvider('refusedDeletions')]
    #[TestDox('refuses to delete $_dataName')]
    public function test_refuses_to_delete(Closure $payload, string $errorKey): void
    {
        $this->actingAs($this->makeUser())
            ->deleteJson(route('registration.admin.search.registrations.destroy'), $payload($this))
            ->assertStatus(422)
            ->assertJsonValidationErrors($errorKey);

        $this->assertSame(1, Guest::count());
        $this->assertNotNull($this->leader->fresh()->group_id);
    }

    #[TestDox('deletes the confirmed registrations and guests, handing over leadership, then reloads the search on close')]
    public function test_deletes_the_confirmed_registrations_and_guests(): void
    {
        $ada = $this->data['registrant'];
        $draftGuest = 'draft-guest:'.$this->data['drafter']->id.':dg-1';

        $this->actingAs($this->makeUser())
            ->deleteJson(route('registration.admin.search.registrations.destroy'), [
                'registrants' => [$this->leader->id],
                'leaders' => [$this->leader->id => $this->member->id],
                'guest_targets' => [$this->guestTarget(), $draftGuest, 'guest:'.$ada->id.':9999', 'draft-guest:'.$ada->id.':x'],
                'guests' => [$this->guestTarget() => 'guest', $draftGuest => 'guest', 'guest:'.$ada->id.':9999' => 'guest', 'draft-guest:'.$ada->id.':x' => 'guest', 'bogus' => 'guest'],
            ])
            ->assertOk()
            ->assertSee('data-reload="1"', false)
            ->assertSee(__('registration::admin.search_deleted', [
                'registrations' => trans_choice('registration::admin.search_deleted_registrations', 1),
                'guests' => trans_choice('registration::admin.search_delete_guest_count', 2),
            ]));

        $this->assertSame(0, $this->answersOf($this->leader));
        $this->assertTrue((bool) $this->member->fresh()->is_group_admin);
        $this->assertSame(0, Guest::count());
        $this->assertSame([], $this->data['draft']->fresh()->guests);
        $this->assertGreaterThan(0, $this->answersOf($ada));
    }

    #[TestDox('deleting a whole group needs no successor and removes the group')]
    public function test_deleting_a_whole_group_needs_no_successor(): void
    {
        $group = $this->leader->group_id;

        $this->actingAs($this->makeUser())
            ->deleteJson(route('registration.admin.search.registrations.destroy'), ['registrants' => [$this->leader->id, $this->member->id]])
            ->assertOk();

        $this->assertNull(Group::find($group));
    }

    #[TestDox('choosing a guest\'s whole registration deletes the registrant and all their guests')]
    public function test_choosing_a_guests_whole_registration_deletes_the_registrant(): void
    {
        $ada = $this->data['registrant'];
        $this->makeGuest($ada, GuestType::Minor);

        $this->actingAs($this->makeUser())
            ->deleteJson(route('registration.admin.search.registrations.destroy'), [
                'guest_targets' => [$this->guestTarget()],
                'guests' => [$this->guestTarget() => 'registrant'],
            ])
            ->assertOk();

        $this->assertSame(0, $this->answersOf($ada));
        $this->assertSame(0, Guest::count());
        $this->assertNotNull($ada->fresh());
    }

    /** The encoded target for Ada's committed guest. */
    public function guestTarget(): string
    {
        return 'guest:'.$this->data['registrant']->id.':'.$this->data['guest']->id;
    }

    /** The dialog for the given encoded targets. */
    private function preview(array $targets)
    {
        return $this->actingAs($this->makeUser())->get(route('registration.admin.search.registrations.preview', ['targets' => $targets]));
    }

    /** A group member's display name, as the dialog shows it. */
    private function memberName(User $user): string
    {
        return $user->name.' Test';
    }

    /** How many answers a user owns. */
    private function answersOf(User $user): int
    {
        return Answer::where('owner_type', $user->getMorphClass())->where('owner_id', $user->id)->count();
    }
}
