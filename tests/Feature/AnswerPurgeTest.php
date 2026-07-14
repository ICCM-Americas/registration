<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Enums\GuestType;
use ConferenceTools\Registration\Enums\QuestionType;
use ConferenceTools\Registration\Models\Answer;
use ConferenceTools\Registration\Models\Draft;
use ConferenceTools\Registration\Models\Group;
use ConferenceTools\Registration\Models\Guest;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\RoomAssignment;
use ConferenceTools\Registration\Models\Section;
use ConferenceTools\Registration\Services\AnswerPurge;
use ConferenceTools\Registration\Services\RegistrationStatus;
use ConferenceTools\Registration\Tests\Concerns\BuildsRegistrationData;
use ConferenceTools\Registration\Tests\Fixtures\User;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * The dashboard's data-reset action: wipes every registration answer, group,
 * draft and room assignment so a fresh testing pass (or a cleared demo seed)
 * starts blank, without ever deleting a user account.
 */
#[TestDox('Answer Purge')]
class AnswerPurgeTest extends TestCase
{
    use BuildsRegistrationData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allowRegistrationManagement();
    }

    #[TestDox('purge deletes all registration data but leaves users intact')]
    public function test_purge_deletes_all_registration_data_but_leaves_users_intact(): void
    {
        $group = $this->makeGroupWithMembers();
        $admin = $group->admin();
        $member = $group->users()->where('is_group_admin', false)->first();

        Draft::factory()->create(['user_id' => $admin->id]);
        RoomAssignment::factory()->create(['assignable_type' => $member->getMorphClass(), 'assignable_id' => $member->id]);
        $guest = Guest::factory()->create(['user_id' => $member->id, 'type' => GuestType::Adult]);
        RoomAssignment::factory()->forGuest()->create(['assignable_id' => $guest->id]);

        $this->assertGreaterThan(0, Answer::query()->count());
        $this->assertSame(2, User::query()->whereNotNull('group_id')->count());
        $this->assertSame(1, Guest::query()->count());

        app(AnswerPurge::class)->purge();

        $this->assertSame(0, Answer::query()->count());
        $this->assertSame(0, Group::query()->count());
        $this->assertSame(0, Draft::query()->count());
        $this->assertSame(0, RoomAssignment::query()->count());
        $this->assertSame(0, Guest::query()->count());

        // The accounts themselves survive, with their registration-completion
        // columns reset (so Group::registeredParticipantCount() reports zero).
        $this->assertSame(2, User::query()->whereIn('id', [$admin->id, $member->id])->count());
        $this->assertSame(0, User::query()->whereNotNull('group_id')->count());
        $this->assertSame(0, User::query()->where('is_group_admin', true)->count());
        $this->assertSame(0, User::query()->where('checked_out', true)->count());
    }

    #[TestDox('a user untouched by registration is left alone')]
    public function test_a_user_untouched_by_registration_is_left_alone(): void
    {
        $bystander = $this->makeUser(['checked_out' => false]);

        app(AnswerPurge::class)->purge();

        $this->assertNotNull($bystander->fresh());
    }

    #[TestDox('deleting all answers from the dashboard card')]
    public function test_deleting_all_answers_from_the_dashboard_card(): void
    {
        $group = $this->makeGroupWithMembers();
        $adminId = $group->admin()->id;

        $this->actingAs($this->makeUser())
            ->delete(route('registration.admin.data.answers.destroy'))
            ->assertRedirect(route('registration.admin.dashboard'))
            ->assertSessionHas('answers_status', __('registration::admin.answers_deleted'));

        $this->assertSame(0, Answer::query()->count());
        $this->assertSame(0, Group::query()->count());
        $this->assertNotNull(User::find($adminId));
    }

    #[TestDox('the data reset route requires the gate')]
    public function test_the_data_reset_route_requires_the_gate(): void
    {
        $this->denyRegistrationManagement();

        $this->actingAs($this->makeUser())
            ->delete(route('registration.admin.data.answers.destroy'))
            ->assertForbidden();
    }

    #[TestDox('the dashboard renders the answers card')]
    public function test_the_dashboard_renders_the_answers_card(): void
    {
        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.dashboard'))
            ->assertOk()
            ->assertSee(__('registration::admin.answers_title'))
            ->assertSee(__('registration::admin.answers_delete'));
    }

    /**
     * Purging every answer is the only way to unlock Question/Option editing
     * once any answer has been recorded (see QuestionBuilderControllerTest's
     * lock tests) — this pins that unlock down end to end, through the
     * dashboard's own delete route rather than the service directly.
     */
    #[TestDox('purging all answers unlocks question editing')]
    public function test_purging_all_answers_unlocks_question_editing(): void
    {
        $this->makeGroupWithMembers();
        $this->assertTrue(app(RegistrationStatus::class)->answersLocked());

        $section = Section::where('key', 'your-details')->first();
        $admin = $this->makeUser();

        $this->actingAs($admin)->post(route('registration.admin.questions.store'), [
            'section_id' => $section->id,
            'label' => 'Still locked',
            'type' => QuestionType::Text->value,
        ])->assertSessionHas('questions_error');
        $this->assertNull(Question::where('key', 'still_locked')->first());

        $this->actingAs($admin)
            ->delete(route('registration.admin.data.answers.destroy'))
            ->assertRedirect(route('registration.admin.dashboard'));

        $this->assertFalse(app(RegistrationStatus::class)->answersLocked());

        $response = $this->actingAs($admin)->post(route('registration.admin.questions.store'), [
            'section_id' => $section->id,
            'label' => 'Unlocked now',
            'type' => QuestionType::Text->value,
        ]);

        $unlocked = Question::where('key', 'unlocked_now')->first();
        $this->assertNotNull($unlocked);
        $response->assertRedirect(route('registration.admin.questions').'#question-'.$unlocked->id);
    }
}
