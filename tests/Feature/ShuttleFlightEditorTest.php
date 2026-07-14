<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Enums\GuestType;
use ConferenceTools\Registration\Models\Answer;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\Setting;
use ConferenceTools\Registration\Services\ReportQuestions;
use ConferenceTools\Registration\Services\ShuttlePlanner;
use ConferenceTools\Registration\Tests\Concerns\BuildsReportData;
use ConferenceTools\Registration\Tests\Fixtures\User;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * The travel-answer editor behind the Shuttle Schedule's "unreadable" names:
 * each name opens the flight answer, exactly as entered, in the shared editor
 * modal so the admin can fix it into something the schedules can read.
 */
#[TestDox('Shuttle Flight Editor')]
class ShuttleFlightEditorTest extends TestCase
{
    use BuildsReportData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allowRegistrationManagement();
        $this->seedReportQuestions();
    }

    /** Nominate the one travel-plans question for both flights, the way this conference's travel2 works. */
    private function shareTravelQuestion(): void
    {
        Setting::put(ReportQuestions::FLIGHT_DEPARTURE_KEY, 'arrivalflight');
    }

    /** The visibility-editor URL for a node. */
    private function editorUrl(string $flight, User $registrant): string
    {
        return route('registration.admin.logistics.shuttles.flight', [$flight, $registrant->id]);
    }

    #[TestDox('unscheduled names link to the flight editor')]
    public function test_unscheduled_names_link_to_the_flight_editor(): void
    {
        $this->shareTravelQuestion();
        $flyer = $this->makeRegistrant('A', 'Vague', [
            'arrivalday' => 'monday',
            'arrivalflight' => 'DL 5678, details TBD',
        ]);
        $this->makeGuest($flyer, GuestType::Adult, ['guestname' => 'Plus One']);

        $content = $this->actingAs($this->makeUser())
            ->get(route('registration.admin.logistics.shuttles'))
            ->assertOk()
            ->getContent();

        // Registrant and guest both appear on the pickup day's unscheduled
        // list; the guest's link carries the registrant's id, since the
        // answer to fix is the registrant's.
        $pickupLink = 'href="'.$this->editorUrl('arrival', $flyer).'"';
        $this->assertSame(2, substr_count($content, $pickupLink));
        $this->assertMatchesRegularExpression(
            '/'.preg_quote($pickupLink, '/').'[^>]*>Plus One<\/a>/s',
            $content,
        );

        // The returns section links the same passengers to the departure editor.
        $this->assertSame(2, substr_count($content, 'href="'.$this->editorUrl('departure', $flyer).'"'));
    }

    #[TestDox('the editor shows the answer and reads it back the way the planner will')]
    public function test_the_editor_shows_the_answer_and_reads_it_back_the_way_the_planner_will(): void
    {
        $this->shareTravelQuestion();
        $flyer = $this->makeRegistrant('A', 'Vague', [
            'arrivalday' => 'monday',
            'arrivalflight' => 'DL 5678, details TBD',
        ]);

        $content = $this->actingAs($this->makeUser())
            ->get($this->editorUrl('arrival', $flyer))
            ->assertOk()
            ->assertSee(__('registration::admin.shuttles_flight_title', ['name' => 'A Vague']))
            ->assertSee('DL 5678, details TBD')
            ->assertDontSee('data-reload')
            ->getContent();

        // A shared travel-plans question feeds both flights, so both read back.
        $arrival = __('registration::admin.shuttles_flight_arrival');
        $departure = __('registration::admin.shuttles_flight_departure');
        $this->assertStringContainsString(__('registration::admin.shuttles_flight_unread', ['flight' => $arrival]), $content);
        $this->assertStringContainsString(__('registration::admin.shuttles_flight_unread', ['flight' => $departure]), $content);
    }

    #[TestDox('saving a fix updates the answer and reports the read times')]
    public function test_saving_a_fix_updates_the_answer_and_reports_the_read_times(): void
    {
        $this->shareTravelQuestion();
        $flyer = $this->makeRegistrant('A', 'Vague', [
            'arrivalday' => 'monday',
            'arrivalflight' => 'DL 5678, details TBD',
        ]);

        $fixed = "Arriving DL5678 on July 14 at 10:00 PM\nDeparting DL-8765 on 7/18 at 9:00 AM";
        $content = $this->actingAs($this->makeUser())
            ->put($this->editorUrl('arrival', $flyer), ['value' => "  $fixed  "])
            ->assertOk()
            ->assertSee('data-reload', escape: false)
            ->assertSee(__('registration::admin.shuttles_flight_saved'))
            ->getContent();

        $arrival = __('registration::admin.shuttles_flight_arrival');
        $departure = __('registration::admin.shuttles_flight_departure');
        $this->assertStringContainsString(__('registration::admin.shuttles_flight_read', ['flight' => $arrival, 'time' => '22:00']), $content);
        $this->assertStringContainsString(__('registration::admin.shuttles_flight_read', ['flight' => $departure, 'time' => '09:00']), $content);

        // Stored trimmed, in place; the planner now schedules the passenger.
        $this->assertSame($fixed, $flyer->fresh()->registrationAnswers()->value('arrivalflight'));
        $this->assertSame('22:00', app(ShuttlePlanner::class)->arrivalRuns()->first()['runs'][0]['time']);
    }

    #[TestDox('clearing the answer removes the passenger from the schedules')]
    public function test_clearing_the_answer_removes_the_passenger_from_the_schedules(): void
    {
        $this->shareTravelQuestion();
        $flyer = $this->makeRegistrant('A', 'Vague', [
            'arrivalday' => 'monday',
            'arrivalflight' => 'DL 5678, details TBD',
        ]);

        $this->actingAs($this->makeUser())
            ->put($this->editorUrl('arrival', $flyer), ['value' => ' '])
            ->assertOk();

        $this->assertNull($flyer->fresh()->registrationAnswers()->value('arrivalflight'));
        $this->assertTrue(app(ShuttlePlanner::class)->arrivalRuns()->isEmpty());
    }

    #[TestDox('saving creates the answer when none exists and titles fall back to the account name')]
    public function test_saving_creates_the_answer_when_none_exists_and_titles_fall_back_to_the_account_name(): void
    {
        // No first/last name answers and no travel answer: the title falls
        // back to the account name and the save writes a fresh row.
        $grounded = $this->makeRegistrant('Ada', 'Lovelace', ['name' => '', 'lastname' => '']);

        $this->actingAs($this->makeUser())
            ->get($this->editorUrl('arrival', $grounded))
            ->assertOk()
            ->assertSee(__('registration::admin.shuttles_flight_title', ['name' => 'Ada']));

        $this->actingAs($this->makeUser())
            ->put($this->editorUrl('arrival', $grounded), ['value' => 'UA 0921 at 10:30 am'])
            ->assertOk();

        $this->assertSame('UA 0921 at 10:30 am', $grounded->fresh()->registrationAnswers()->value('arrivalflight'));
    }

    #[TestDox('the editor title shows the full name, not the badge name')]
    public function test_the_editor_title_shows_the_full_name_not_the_badge_name(): void
    {
        $flyer = $this->makeRegistrant('A', 'Vague', ['badgename' => 'Sky Traveler']);

        $this->actingAs($this->makeUser())
            ->get($this->editorUrl('arrival', $flyer))
            ->assertOk()
            ->assertSee(__('registration::admin.shuttles_flight_title', ['name' => 'A Vague']))
            ->assertDontSee('Sky Traveler');
    }

    #[TestDox('separate flight questions each read back only their own flight')]
    public function test_separate_flight_questions_each_read_back_only_their_own_flight(): void
    {
        $flyer = $this->makeRegistrant('A', 'One', [
            'arrivalday' => 'monday',
            'arrivalflight' => 'around lunch',
            'departureflight' => '18:00',
        ]);

        $arrival = __('registration::admin.shuttles_flight_arrival');
        $departure = __('registration::admin.shuttles_flight_departure');

        $this->actingAs($this->makeUser())
            ->get($this->editorUrl('arrival', $flyer))
            ->assertOk()
            ->assertSee('Arrivalflight') // the nominated question's own label
            ->assertSee(__('registration::admin.shuttles_flight_unread', ['flight' => $arrival]))
            ->assertDontSee(__('registration::admin.shuttles_flight_read', ['flight' => $departure, 'time' => '18:00']));

        $this->actingAs($this->makeUser())
            ->get($this->editorUrl('departure', $flyer))
            ->assertOk()
            ->assertSee('Departureflight')
            ->assertSee(__('registration::admin.shuttles_flight_read', ['flight' => $departure, 'time' => '18:00']))
            ->assertDontSee(__('registration::admin.shuttles_flight_unread', ['flight' => $arrival]));
    }

    #[TestDox('a multi row answer shows joined and collapses to one row on save')]
    public function test_a_multi_row_answer_shows_joined_and_collapses_to_one_row_on_save(): void
    {
        // An admin may nominate any question; a checkbox answer stores one row
        // per selection. The editor shows them joined and saves back one row.
        Setting::put(ReportQuestions::FLIGHT_ARRIVAL_KEY, 'directorypref');
        $flyer = $this->makeRegistrant('A', 'One', ['directorypref' => ['ShowBadgeName', 'ShowOrg']]);

        $this->actingAs($this->makeUser())
            ->get($this->editorUrl('arrival', $flyer))
            ->assertOk()
            ->assertSee("ShowBadgeName\nShowOrg");

        $this->actingAs($this->makeUser())
            ->put($this->editorUrl('arrival', $flyer), ['value' => '10:30'])
            ->assertOk();

        $question = Question::where('key', 'directorypref')->firstOrFail();
        $rows = Answer::where('question_id', $question->id)
            ->where('owner_type', $flyer->getMorphClass())
            ->where('owner_id', $flyer->id)
            ->get();
        $this->assertCount(1, $rows);
        $this->assertSame('10:30', $rows->first()->value);
    }

    #[TestDox('an overlong answer is rejected with a validation error')]
    public function test_an_overlong_answer_is_rejected_with_a_validation_error(): void
    {
        $flyer = $this->makeRegistrant('A', 'One');

        $this->actingAs($this->makeUser())
            ->putJson($this->editorUrl('arrival', $flyer), ['value' => str_repeat('x', 5001)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('value');
    }

    #[DataProvider('missingTargets')]
    #[TestDox('the editor is not found without its question or passenger')]
    public function test_the_editor_is_not_found_without_its_question_or_passenger(?string $nominated, string $flight, bool $realUser): void
    {
        Setting::put(ReportQuestions::FLIGHT_ARRIVAL_KEY, $nominated);
        $flyer = $this->makeRegistrant('A', 'One');

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.logistics.shuttles.flight', [$flight, $realUser ? $flyer->id : $flyer->id + 1000]))
            ->assertNotFound();
    }

    /** The missing targets for the data provider. */
    public static function missingTargets(): array
    {
        return [
            'unnominated flight question' => [null, 'arrival', true],
            'nomination of a deleted question' => ['nosuch', 'arrival', true],
            'unknown flight segment' => ['arrivalflight', 'other', true],
            'unknown passenger' => ['arrivalflight', 'arrival', false],
        ];
    }

    #[TestDox('the editor requires the gate')]
    public function test_the_editor_requires_the_gate(): void
    {
        $this->denyRegistrationManagement();
        $flyer = $this->makeRegistrant('A', 'One');

        $this->actingAs($this->makeUser())->get($this->editorUrl('arrival', $flyer))->assertForbidden();
        $this->actingAs($this->makeUser())->put($this->editorUrl('arrival', $flyer), ['value' => '10:30'])->assertForbidden();
    }
}
