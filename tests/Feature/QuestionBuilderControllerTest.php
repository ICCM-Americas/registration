<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Database\Seeders\SystemQuestionsSeeder;
use ConferenceTools\Registration\Enums\BooleanOperator;
use ConferenceTools\Registration\Enums\PerDiemScope;
use ConferenceTools\Registration\Enums\QuestionScope;
use ConferenceTools\Registration\Enums\QuestionType;
use ConferenceTools\Registration\Models\ConditionGroup;
use ConferenceTools\Registration\Models\Question;
use ConferenceTools\Registration\Models\Section;
use ConferenceTools\Registration\Models\Translation;
use ConferenceTools\Registration\Tests\Concerns\BuildsRegistrationData;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/** Feature tests for Question Builder Controller. */
#[TestDox('Question Builder Controller')]
class QuestionBuilderControllerTest extends TestCase
{
    use BuildsRegistrationData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allowRegistrationManagement();
        $this->seedQuestionConfig();
    }

    #[TestDox('builder index renders sections and questions')]
    public function test_builder_index_renders_sections_and_questions(): void
    {
        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.questions'))
            ->assertOk()
            ->assertSee('Your details')
            ->assertSee('Organization')
            // Each section card is an anchor target (the test drive's exit
            // button deep-links to the section being tested).
            ->assertSee('id="section-'.Section::firstWhere('title', 'Your details')->id.'"', false)
            ->assertSee('Sortable.min.js', false);
    }

    #[TestDox('the protected system section and questions are tagged and offer no delete button')]
    public function test_the_protected_system_section_and_questions_are_tagged_and_offer_no_delete_button(): void
    {
        $section = Section::where('is_system', true)->firstOrFail();
        $guestQuestion = Question::where('key', Question::GUEST_TRIGGER_KEY)->firstOrFail();

        $response = $this->actingAs($this->makeUser())
            ->get(route('registration.admin.questions'))
            ->assertOk();

        $response->assertSeeInOrder([
            'id="section-'.$section->id.'"',
            'system',
            'id="question-'.$guestQuestion->id.'"',
            'system',
        ], false);
        // A trailing quote closes the match on the exact destroy-form action —
        // without it, the destroy URL is a substring of the Visibility link's
        // URL (".../sections/1" vs ".../sections/1/visibility") and would
        // always "match". The class pins which form, since the inline title
        // form posts (as a PUT) to the destroy form's very URL.
        $response->assertDontSee('action="'.route('registration.admin.sections.destroy', $section).'" class="js-confirm-submit"', false);
        $response->assertDontSee('action="'.route('registration.admin.questions.destroy', $guestQuestion).'"', false);
        // Protection is against deletion, not renaming.
        $response->assertSee('action="'.route('registration.admin.sections.update', $section).'" class="js-section-title', false);
    }

    #[TestDox('builder rows carry toggleable tags reflecting their state')]
    public function test_builder_rows_carry_toggleable_tags_reflecting_their_state(): void
    {
        // All three dynamic tags stay in the DOM (d-none when off) so the
        // editor modals can toggle them without a page reload.
        $admin = $this->makeUser();
        $section = Section::where('key', 'your-details')->first();
        $section->storeTranslation('fr', 'title', 'Vos coordonnées');
        $orgtype = Question::where('key', 'orgtype')->first();
        $orgtype->options()->first()->storeTranslation('fr', 'label', 'Entreprise');
        $nickname = Question::where('key', 'nickname')->first();

        $html = $this->actingAs($admin)
            ->get(route('registration.admin.questions'))
            ->assertOk()
            ->getContent();

        $sectionRow = Str::before(Str::after($html, sprintf('data-section-id="%d"', $section->id)), '<ul');
        $this->assertStringContainsString('ml-1" data-badge="translated"', $sectionRow);

        // An option-only translation marks the whole question translated.
        $translatedRow = Str::before(Str::after($html, sprintf('data-question-id="%d"', $orgtype->id)), '</li>');
        $this->assertStringContainsString('badge-primary" data-badge="translated"', $translatedRow);

        $plainRow = Str::before(Str::after($html, sprintf('data-question-id="%d"', $nickname->id)), '</li>');
        $this->assertStringContainsString('d-none" data-badge="translated"', $plainRow);
        $this->assertStringContainsString('d-none" data-badge="hidden"', $plainRow);
        $this->assertStringContainsString('d-none" data-badge="conditional"', $plainRow);
    }

    #[TestDox('builder requires the gate')]
    public function test_builder_requires_the_gate(): void
    {
        $this->denyRegistrationManagement();

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.questions'))
            ->assertForbidden();
    }

    #[TestDox('reorder moves a question between sections')]
    public function test_reorder_moves_a_question_between_sections(): void
    {
        $details = Section::where('key', 'your-details')->first();
        $accommodation = Section::where('key', 'accommodation')->first();
        $nickname = Question::where('key', 'nickname')->first();

        $this->actingAs($this->makeUser())->postJson(route('registration.admin.questions.reorder'), [
            'sections' => [
                ['id' => $details->id, 'position' => 1],
                ['id' => $accommodation->id, 'position' => 0],
            ],
            'questions' => [
                ['id' => $nickname->id, 'section_id' => $accommodation->id, 'position' => 5],
            ],
        ])->assertOk()->assertJson(['status' => 'ok']);

        $nickname->refresh();
        $this->assertSame($accommodation->id, $nickname->section_id);
        $this->assertSame(5, $nickname->position);
        $this->assertSame(0, $accommodation->fresh()->position);
        $this->assertSame(1, $details->fresh()->position);
    }

    /** @return array<int, array{line: string}> one submitted option row per line */
    private function optionLines(string ...$lines): array
    {
        return array_map(fn (string $line): array => ['line' => $line], $lines);
    }

    #[TestDox('store question creates a question with options')]
    public function test_store_question_creates_a_question_with_options(): void
    {
        $section = Section::where('key', 'your-details')->first();

        $response = $this->actingAs($this->makeUser())->post(route('registration.admin.questions.store'), [
            'section_id' => $section->id,
            'label' => 'T-shirt size',
            'type' => QuestionType::Select->value,
            'required' => '1',
            // The blank row in the middle is ignored, not turned into an option;
            // the sixth field of the last row is the option's help text.
            'options' => $this->optionLines('s | Small', '', 'm | Medium', 'l | Large | 5', 'xl | Extra large |  |  |  | Big and roomy'),
        ]);

        $question = Question::where('key', 't_shirt_size')->first();
        $this->assertNotNull($question);
        // Redirects back to roughly where the admin was: the new question's own row.
        $response->assertRedirect(route('registration.admin.questions').'#question-'.$question->id);
        $this->assertSame($section->id, $question->section_id);
        $this->assertTrue($question->required);
        $this->assertCount(4, $question->options);
        $this->assertEqualsWithDelta(5.0, (float) $question->options->firstWhere('value', 'l')->cost, 0.001);
        $this->assertSame('Big and roomy', $question->options->firstWhere('value', 'xl')->description);
    }

    #[TestDox('store question parses per diem days and guest scope')]
    public function test_store_question_parses_per_diem_days_and_guest_scope(): void
    {
        $section = Section::where('key', 'your-details')->first();

        $response = $this->actingAs($this->makeUser())->post(route('registration.admin.questions.store'), [
            'section_id' => $section->id,
            'label' => 'Arrival role',
            'type' => QuestionType::Radio->value,
            // Empty cost, per-diem days present; "guests" in the fifth field covers a guest.
            'options' => $this->optionLines('setup | Set-up team |  | 2', 'partner | Partner |  | 3 | guests'),
        ]);

        $question = Question::where('key', 'arrival_role')->first();
        $response->assertRedirect(route('registration.admin.questions').'#question-'.$question->id);
        $setup = $question->options->firstWhere('value', 'setup');
        $partner = $question->options->firstWhere('value', 'partner');

        $this->assertNull($setup->cost);
        $this->assertSame(2, $setup->per_diem_days);
        $this->assertSame(PerDiemScope::Attendee, $setup->per_diem_scope);
        $this->assertSame(3, $partner->per_diem_days);
        $this->assertSame(PerDiemScope::AttendeeAndGuests, $partner->per_diem_scope);
    }

    #[TestDox('edit form serializes per diem back to the option line')]
    public function test_edit_form_serializes_per_diem_back_to_the_option_line(): void
    {
        $section = Section::where('key', 'your-details')->first();
        $question = Question::create([
            'section_id' => $section->id, 'key' => 'arrival_role', 'type' => QuestionType::Radio->value,
            'label' => 'Arrival role', 'position' => 9, 'required' => false, 'enabled' => true,
        ]);
        $question->options()->create([
            'value' => 'partner', 'label' => 'Partner', 'per_diem_days' => 3,
            'per_diem_scope' => PerDiemScope::AttendeeAndGuests, 'position' => 0,
        ]);

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.questions.edit', $question))
            ->assertOk()
            // Empty cost is kept as a placeholder so the positional per-diem fields line up.
            ->assertSee('partner | Partner |  | 3 | guests', false);
    }

    #[DataProvider('keyScopes')]
    #[TestDox('store question derives a key unique across every scope')]
    public function test_store_question_derives_a_key_unique_across_every_scope(QuestionScope $scope): void
    {
        $section = Section::create(['scope' => $scope->value, 'key' => 'keyed-'.$scope->value, 'title' => 'Keyed', 'position' => 99, 'enabled' => true]);

        // "name" already exists in the participant scope (seeded) → suffixed,
        // whatever the new question's own scope.
        $this->actingAs($this->makeUser())->post(route('registration.admin.questions.store'), [
            'section_id' => $section->id,
            'label' => 'Name',
            'type' => QuestionType::Text->value,
        ])->assertRedirect();

        $this->assertSame($section->id, Question::where('key', 'name_2')->sole()->section_id);
    }

    /** The scopes a new question may land in, for the data provider. */
    public static function keyScopes(): array
    {
        return [
            'participant' => [QuestionScope::Participant],
            'group' => [QuestionScope::Group],
            'guest' => [QuestionScope::Guest],
        ];
    }

    #[TestDox('update question changes fields and options')]
    public function test_update_question_changes_fields_and_options(): void
    {
        $gender = Question::where('key', 'gender')->first();

        $this->actingAs($this->makeUser())->put(route('registration.admin.questions.update', $gender), [
            'section_id' => $gender->section_id,
            'key' => 'gender',
            'label' => 'Gender identity',
            'type' => QuestionType::Radio->value,
            'options' => $this->optionLines('m | Male', 'f | Female', 'x | Other'),
        ])->assertRedirect(route('registration.admin.questions').'#question-'.$gender->id);

        $gender->refresh()->load('options');
        $this->assertSame('Gender identity', $gender->label);
        $this->assertCount(3, $gender->options);
    }

    #[TestDox('update question reconciles options keeping translations of survivors')]
    public function test_update_question_reconciles_options_keeping_translations_of_survivors(): void
    {
        $admin = $this->makeUser();
        $gender = Question::where('key', 'gender')->first();
        $male = $gender->options()->firstWhere('value', 'm');
        $female = $gender->options()->firstWhere('value', 'f');
        $male->storeTranslation('fr', 'label', 'Homme');
        $female->storeTranslation('fr', 'label', 'Femme');

        $this->actingAs($admin)->put(route('registration.admin.questions.update', $gender), [
            'section_id' => $gender->section_id,
            'key' => 'gender',
            'label' => 'Gender',
            'type' => QuestionType::Radio->value,
            'options' => [
                ['id' => $male->id, 'line' => 'm | Male or man'],
                ['line' => 'x | Other'],
            ],
        ]);

        // "m" kept its row (reconciled by its submitted id), its new label,
        // and its translation; the dropped "f" took its translation with it;
        // "x" is new.
        $kept = $gender->options()->firstWhere('value', 'm');
        $this->assertSame($male->id, $kept->id);
        $this->assertSame('Male or man', $kept->label);
        $this->assertSame('Homme', $kept->translate('label', 'fr'));
        $this->assertNull($gender->options()->firstWhere('value', 'f'));
        $this->assertSame(0, Translation::where('value', 'Femme')->count());
        $this->assertNotNull($gender->options()->firstWhere('value', 'x'));
    }

    #[TestDox('store question accepts repeated values as conditional variants')]
    public function test_store_question_accepts_repeated_values_as_conditional_variants(): void
    {
        $section = Section::where('key', 'your-details')->first();

        // The same value on two rows: variants of one stored value whose
        // labels differ (each gets its own visibility rule after saving).
        $response = $this->actingAs($this->makeUser())->post(route('registration.admin.questions.store'), [
            'section_id' => $section->id,
            'label' => 'Pass',
            'type' => QuestionType::Radio->value,
            'options' => $this->optionLines('full | Full (Member)', 'full | Full (Guest)', 'day | Day'),
        ]);

        $pass = Question::where('key', 'pass')->first();
        $response->assertRedirect(route('registration.admin.questions').'#question-'.$pass->id);
        $options = $pass->options;
        $this->assertSame(['full', 'full', 'day'], $options->pluck('value')->all());
        $this->assertSame(['Full (Member)', 'Full (Guest)', 'Day'], $options->pluck('label')->all());
    }

    #[TestDox('update question reconciles rows by id even across shared values')]
    public function test_update_question_reconciles_rows_by_id_even_across_shared_values(): void
    {
        $admin = $this->makeUser();
        $gender = Question::where('key', 'gender')->first();

        $this->actingAs($admin)->put(route('registration.admin.questions.update', $gender), [
            'section_id' => $gender->section_id, 'key' => 'gender', 'label' => 'Gender',
            'type' => QuestionType::Radio->value,
            'options' => $this->optionLines('m | First man', 'm | Second man'),
        ]);

        [$first, $second] = $gender->options()->orderBy('position')->get();
        $second->storeTranslation('fr', 'label', 'Deuxième');
        $rule = $second->conditionGroups()->create(['operator' => BooleanOperator::And->value]);

        // Re-saving relabeled rows keeps each row by its id — and with it the
        // second row's translation and visibility rule.
        $this->actingAs($admin)->put(route('registration.admin.questions.update', $gender), [
            'section_id' => $gender->section_id, 'key' => 'gender', 'label' => 'Gender',
            'type' => QuestionType::Radio->value,
            'options' => [
                ['id' => $first->id, 'line' => 'm | First man edited'],
                ['id' => $second->id, 'line' => 'm | Second man edited'],
            ],
        ]);

        $options = $gender->options()->orderBy('position')->get();
        $this->assertSame([$first->id, $second->id], $options->pluck('id')->all());
        $this->assertSame('Deuxième', $options[1]->translate('label', 'fr'));
        $this->assertNotNull(ConditionGroup::find($rule->id));

        // Dropping the second row removes that option and its rule with it.
        $this->actingAs($admin)->put(route('registration.admin.questions.update', $gender), [
            'section_id' => $gender->section_id, 'key' => 'gender', 'label' => 'Gender',
            'type' => QuestionType::Radio->value,
            'options' => [['id' => $first->id, 'line' => 'm | First man edited']],
        ]);

        $this->assertSame([$first->id], $gender->options()->pluck('id')->all());
        $this->assertNull(ConditionGroup::find($rule->id));
    }

    #[TestDox('update question orders options by submitted row order')]
    public function test_update_question_orders_options_by_submitted_row_order(): void
    {
        // The rows arrive in their dragged order; positions follow it.
        $admin = $this->makeUser();
        $gender = Question::where('key', 'gender')->first();
        $male = $gender->options()->firstWhere('value', 'm');
        $female = $gender->options()->firstWhere('value', 'f');

        $this->actingAs($admin)->put(route('registration.admin.questions.update', $gender), [
            'section_id' => $gender->section_id, 'key' => 'gender', 'label' => 'Gender',
            'type' => QuestionType::Radio->value,
            'options' => [
                ['id' => $female->id, 'line' => 'f | Female'],
                ['id' => $male->id, 'line' => 'm | Male'],
            ],
        ]);

        $this->assertSame(['f', 'm'], $gender->options()->orderBy('position')->pluck('value')->all());
    }

    #[TestDox('edit question form links each option to its visibility editor and anchors its row')]
    public function test_edit_question_form_links_each_option_to_its_visibility_editor(): void
    {
        $admin = $this->makeUser();
        $gender = Question::where('key', 'gender')->first();
        $male = $gender->options()->firstWhere('value', 'm');

        $this->actingAs($admin)
            ->get(route('registration.admin.questions.edit', $gender))
            ->assertOk()
            ->assertSee(route('registration.admin.options.visibility', $male))
            ->assertSee('id="option-'.$male->id.'"', false);
    }

    #[TestDox('store option persists the row and returns its visibility url')]
    public function test_store_option_persists_the_row_and_returns_its_visibility_url(): void
    {
        // Committing a new row's line on a saved question persists the option
        // over AJAX right away (a visibility rule needs an id), appended after
        // the existing options; the response carries what the form's script
        // needs to add the row's Visibility button without a reload.
        $admin = $this->makeUser();
        $gender = Question::where('key', 'gender')->first();
        $lastPosition = (int) $gender->options()->max('position');

        $response = $this->actingAs($admin)
            ->postJson(route('registration.admin.questions.options.store', $gender), [
                'line' => 'nb | Non-binary',
            ])
            ->assertOk();

        $option = $gender->options()->find($response->json('id'));
        $this->assertSame('nb', $option->value);
        $this->assertSame('Non-binary', $option->label);
        $this->assertSame($lastPosition + 1, $option->position);
        $this->assertSame(route('registration.admin.options.visibility', $option), $response->json('visibility_url'));
    }

    #[TestDox('store option rejects a blank or unparseable line')]
    public function test_store_option_rejects_a_blank_or_unparseable_line(): void
    {
        $admin = $this->makeUser();
        $gender = Question::where('key', 'gender')->first();
        $url = route('registration.admin.questions.options.store', $gender);

        $this->actingAs($admin)->postJson($url, ['line' => ''])->assertStatus(422);
        // A line whose value part is empty parses to nothing.
        $this->actingAs($admin)->postJson($url, ['line' => ' | label only'])->assertStatus(422);

        $this->assertSame(2, $gender->options()->count());
    }

    #[TestDox('destroy question')]
    public function test_destroy_question(): void
    {
        $nickname = Question::where('key', 'nickname')->first();

        $this->actingAs($this->makeUser())
            ->delete(route('registration.admin.questions.destroy', $nickname))
            ->assertRedirect(route('registration.admin.questions'));

        $this->assertNull(Question::where('key', 'nickname')->first());
    }

    #[TestDox('a protected system question cannot be deleted')]
    public function test_a_protected_system_question_cannot_be_deleted(): void
    {
        $guestQuestion = Question::where('key', Question::GUEST_TRIGGER_KEY)->firstOrFail();

        $this->actingAs($this->makeUser())
            ->delete(route('registration.admin.questions.destroy', $guestQuestion))
            ->assertForbidden();

        $this->assertNotNull(Question::where('key', Question::GUEST_TRIGGER_KEY)->first());
    }

    #[TestDox('updating a protected system question keeps its key and type but saves the label')]
    public function test_updating_a_protected_system_question_keeps_its_key_and_type_but_saves_the_label(): void
    {
        $guestQuestion = Question::where('key', Question::GUEST_TRIGGER_KEY)->firstOrFail();

        // "enabled" rides along as a tampered/stray field — there is no form
        // control for it (questions have no disabled state at all, only the
        // normal visibility mechanism), and the controller must ignore it.
        $this->actingAs($this->makeUser())->put(route('registration.admin.questions.update', $guestQuestion), [
            'section_id' => $guestQuestion->section_id,
            'key' => 'tampered_key',
            'type' => QuestionType::Text->value,
            'label' => 'Bringing anyone who is not attending?',
            'enabled' => '0',
        ]);

        $guestQuestion->refresh();
        $this->assertSame(Question::GUEST_TRIGGER_KEY, $guestQuestion->key);
        $this->assertSame(QuestionType::YesNo, $guestQuestion->type);
        $this->assertSame('Bringing anyone who is not attending?', $guestQuestion->label);
        $this->assertTrue($guestQuestion->enabled);
        // Its fixed Yes/No options are untouched by the (absent) options editor.
        $this->assertSame(['Yes', 'No'], $guestQuestion->options()->orderBy('position')->pluck('value')->all());
    }

    #[TestDox('the edit form locks key and type and hides the options editor for a protected system question')]
    public function test_the_edit_form_locks_key_and_type_and_hides_the_options_editor_for_a_protected_system_question(): void
    {
        $guestQuestion = Question::where('key', Question::GUEST_TRIGGER_KEY)->firstOrFail();

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.questions.edit', $guestQuestion))
            ->assertOk()
            ->assertSee('name="key" id="key" class="form-control " value="'.Question::GUEST_TRIGGER_KEY.'" disabled', false)
            ->assertSee('type="hidden" name="type" value="yes_no"', false)
            ->assertDontSee('id="options-group"', false);
    }

    #[TestDox('update section changes title description and enabled')]
    public function test_update_section_changes_title_description_and_enabled(): void
    {
        $section = Section::where('key', 'your-details')->first();

        // The save lands back on the builder at the section it edited.
        $this->actingAs($this->makeUser())->put(route('registration.admin.sections.update', $section), [
            'title' => 'Your info', 'description' => 'Tell us about you', 'enabled' => '0',
        ])->assertRedirect(route('registration.admin.questions').'#section-'.$section->id);

        $section->refresh();
        $this->assertSame('Your info', $section->title);
        $this->assertSame('Tell us about you', $section->description);
        $this->assertFalse((bool) $section->enabled);
    }

    #[TestDox('the builder edits a section title in place on its card header')]
    public function test_the_builder_edits_a_section_title_in_place_on_its_card_header(): void
    {
        $section = Section::where('key', 'your-details')->first();

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.questions'))
            ->assertOk()
            ->assertSee('action="'.route('registration.admin.sections.update', $section).'" class="js-section-title', false)
            ->assertSee('value="Your details" maxlength="255" required', false);
    }

    #[TestDox('saving a title alone leaves the section description and enabled flag alone')]
    public function test_saving_a_title_alone_leaves_the_section_description_and_enabled_flag_alone(): void
    {
        $section = Section::where('key', 'your-details')->first();
        $section->update(['description' => 'Tell us about you', 'enabled' => true]);

        $this->actingAs($this->makeUser())
            ->put(route('registration.admin.sections.update', $section), ['title' => 'Your info'])
            ->assertRedirect(route('registration.admin.questions').'#section-'.$section->id);

        $section->refresh();
        $this->assertSame('Your info', $section->title);
        $this->assertSame('Tell us about you', $section->description);
        $this->assertTrue((bool) $section->enabled);
    }

    #[TestDox('create question form renders')]
    public function test_create_question_form_renders(): void
    {
        $section = Section::where('key', 'your-details')->first();

        // A not-yet-saved question has no row to translate, so no button (the
        // class name itself still appears in the form's script, which builds
        // a new option row's Visibility button from it after saving).
        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.questions.create', ['section' => $section->id]))
            ->assertOk()
            ->assertDontSee('js-editor-link">', false)
            // The label field is capped and shows a live character count.
            ->assertSee('maxlength="4096"', false)
            ->assertSee('id="label-count"', false)
            // Help text is multi-line, like the label.
            ->assertSee('<textarea name="help_text"', false)
            // Options are individual rows added by button, not a bulk textarea.
            ->assertSee('id="option-add"', false)
            ->assertDontSee('<textarea name="options"', false)
            // The Placeholder field and Options fieldset follow the chosen
            // type: the script hides them where they don't apply, driven by
            // these per-type lists.
            ->assertSee('id="placeholder-group"', false)
            ->assertSee('id="options-group"', false)
            ->assertSee('["select","radio","checkbox","yes_no"]', false)
            ->assertSee('["text","textarea","email","tel","url","number","discount_code"]', false);
    }

    #[TestDox('edit question form shows existing options as text')]
    public function test_edit_question_form_shows_existing_options_as_text(): void
    {
        // The gender question has seeded options, which the form renders as the
        // "value | label" text the builder edits.
        $gender = Question::where('key', 'gender')->first();

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.questions.edit', $gender))
            ->assertOk()
            ->assertSee('Male', false)
            // The shared visibility and translations modals are both reachable
            // straight from the form.
            ->assertSee(route('registration.admin.questions.visibility', $gender))
            ->assertSee(route('registration.admin.translations', ['question', $gender->id]))
            ->assertSee('js-editor-link');
    }

    #[TestDox('store and destroy section')]
    public function test_store_and_destroy_section(): void
    {
        $this->actingAs($this->makeUser())->post(route('registration.admin.sections.store'), [
            'scope' => QuestionScope::Participant->value,
            'title' => 'Dietary needs',
        ])->assertRedirect(route('registration.admin.questions'));

        $section = Section::where('title', 'Dietary needs')->first();
        $this->assertNotNull($section);

        $this->actingAs($this->makeUser())
            ->delete(route('registration.admin.sections.destroy', $section))
            ->assertRedirect(route('registration.admin.questions'));

        $this->assertNull(Section::where('title', 'Dietary needs')->first());
    }

    #[TestDox('the protected system questions section cannot be deleted')]
    public function test_the_protected_system_questions_section_cannot_be_deleted(): void
    {
        $section = Section::where('is_system', true)->firstOrFail();

        $this->actingAs($this->makeUser())
            ->delete(route('registration.admin.sections.destroy', $section))
            ->assertForbidden();

        $this->assertNotNull($section->fresh());
    }

    #[TestDox('a section holding a system question dragged out of its own section cannot be deleted')]
    public function test_a_section_holding_a_dragged_out_system_question_cannot_be_deleted(): void
    {
        $guestQuestion = Question::where('key', Question::GUEST_TRIGGER_KEY)->firstOrFail();
        $destination = Section::where('key', 'your-details')->firstOrFail();
        $guestQuestion->update(['section_id' => $destination->id]);

        $this->actingAs($this->makeUser())
            ->delete(route('registration.admin.sections.destroy', $destination))
            ->assertRedirect(route('registration.admin.questions'))
            ->assertSessionHas('questions_error');

        $this->assertNotNull($destination->fresh());
    }

    #[TestDox('dragging a system question between sections persists its new section and position')]
    public function test_dragging_a_system_question_between_sections_persists_its_new_section_and_position(): void
    {
        $guestQuestion = Question::where('key', Question::GUEST_TRIGGER_KEY)->firstOrFail();
        $destination = Section::where('key', 'your-details')->firstOrFail();

        $this->actingAs($this->makeUser())->postJson(route('registration.admin.questions.reorder'), [
            'sections' => [],
            'questions' => [
                ['id' => $guestQuestion->id, 'section_id' => $destination->id, 'position' => 0],
            ],
        ])->assertOk();

        $guestQuestion->refresh();
        $this->assertSame($destination->id, $guestQuestion->section_id);
        $this->assertSame(0, $guestQuestion->position);
    }

    #[TestDox('the index page offers a third guest questions scope')]
    public function test_the_index_page_offers_a_third_guest_questions_scope(): void
    {
        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.sections.store'), [
                'scope' => QuestionScope::Guest->value,
                'title' => 'Guest details',
            ])->assertRedirect(route('registration.admin.questions'));

        $section = Section::where('title', 'Guest details')->first();
        $this->assertNotNull($section);
        $this->assertSame(QuestionScope::Guest, $section->scope);

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.questions'))
            ->assertOk()
            ->assertSee(__('registration::admin.scope_guest'))
            ->assertSee('Guest details');
    }

    #[TestDox('the index page offers the group member questions scope, including the seeded system section')]
    public function test_the_index_page_offers_the_group_member_questions_scope(): void
    {
        app(SystemQuestionsSeeder::class)->run();

        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.sections.store'), [
                'scope' => QuestionScope::GroupMember->value,
                'title' => 'Group member details',
            ])->assertRedirect(route('registration.admin.questions'));

        $section = Section::where('title', 'Group member details')->first();
        $this->assertNotNull($section);
        $this->assertSame(QuestionScope::GroupMember, $section->scope);

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.questions'))
            ->assertOk()
            ->assertSee(__('registration::admin.scope_group_member'))
            ->assertSee('Group member details')
            // The seeded system section (SystemQuestionsSeeder::GROUP_MEMBER_SECTION_KEY) is
            // reachable through this same scope bucket, not just admin-authored sections.
            ->assertSee('Group Member Details');
    }

    #[TestDox('a guest scope question can be created edited and reordered')]
    public function test_a_guest_scope_question_can_be_created_edited_and_reordered(): void
    {
        $section = Section::where('title', 'Guest details')->first()
            ?? Section::create(['scope' => QuestionScope::Guest->value, 'key' => 'guest-details', 'title' => 'Guest details', 'position' => 0, 'enabled' => true]);

        $this->actingAs($this->makeUser())->post(route('registration.admin.questions.store'), [
            'section_id' => $section->id,
            'label' => 'Guest name',
            'type' => QuestionType::Text->value,
        ])->assertRedirect();

        $question = Question::where('key', 'guest_name')->first();
        $this->assertNotNull($question);
        $this->assertSame($section->id, $question->section_id);

        $this->actingAs($this->makeUser())->postJson(route('registration.admin.questions.reorder'), [
            'sections' => [['id' => $section->id, 'position' => 0]],
            'questions' => [['id' => $question->id, 'section_id' => $section->id, 'position' => 3]],
        ])->assertOk()->assertJson(['status' => 'ok']);

        $this->assertSame(3, $question->fresh()->position);
    }

    #[TestDox('translate value persists through store and update')]
    public function test_translate_value_persists_through_store_and_update(): void
    {
        $section = Section::where('key', 'your-details')->first();

        $response = $this->actingAs($this->makeUser())->post(route('registration.admin.questions.store'), [
            'section_id' => $section->id,
            'label' => 'Display name',
            'type' => QuestionType::Select->value,
            'translate_value' => '1',
            'options' => $this->optionLines('mr | Mr'),
        ]);

        $question = Question::where('key', 'display_name')->first();
        $this->assertTrue($question->translate_value);
        $response->assertRedirect(route('registration.admin.questions').'#question-'.$question->id);

        $this->actingAs($this->makeUser())->put(route('registration.admin.questions.update', $question), [
            'section_id' => $question->section_id,
            'key' => 'display_name',
            'label' => 'Display name',
            'type' => QuestionType::Select->value,
            'options' => $this->optionLines('mr | Mr'),
        ])->assertRedirect(route('registration.admin.questions').'#question-'.$question->id);

        $this->assertFalse($question->fresh()->translate_value);
    }

    /**
     * Once any answer has been recorded (or registration is open), Question
     * and Option mutations refuse to save — {@see admin()} seeds real answers
     * via its group, so it's the actor used to exercise the lock throughout
     * this section, unlike every test above (which deliberately uses an
     * answer-free {@see BuildsRegistrationData::makeUser()} actor so the lock
     * doesn't interfere with what they're actually testing).
     */
    #[TestDox('store question is blocked while locked')]
    public function test_store_question_is_blocked_while_locked(): void
    {
        $admin = $this->admin();
        $section = Section::where('key', 'your-details')->first();

        $this->actingAs($admin)->post(route('registration.admin.questions.store'), [
            'section_id' => $section->id,
            'label' => 'Blocked question',
            'type' => QuestionType::Text->value,
        ])->assertRedirect(route('registration.admin.questions'))
            ->assertSessionHas('questions_error');

        $this->assertNull(Question::where('key', 'blocked_question')->first());
    }

    /**
     * A locked submission for the accommodation question: new texts for the
     * question and its hotel option, alongside structural changes the lock
     * must ignore.
     */
    private function lockedAccommodationUpdate(Question $accommodation, array $extra = []): array
    {
        $hotel = $accommodation->options()->where('value', 'hotel')->first();
        $none = $accommodation->options()->where('value', 'none')->first();

        return $extra + [
            'section_id' => Section::where('key', 'your-details')->first()->id,
            'key' => 'lodging',
            'type' => QuestionType::Text->value,
            'required' => '0',
            'label' => 'Lodging',
            'help_text' => 'Pick one.',
            'options' => [
                ['id' => $hotel->id, 'value' => 'Hotel room', 'label' => 'Hotel room', 'description' => 'Shared', 'line' => 'x | y | 999'],
                ['id' => $none->id, 'value' => 'none', 'label' => '', 'description' => ''],
            ],
        ];
    }

    #[TestDox('update question saves only texts while locked and updates stored answers')]
    public function test_update_question_saves_only_texts_while_locked_and_updates_stored_answers(): void
    {
        $admin = $this->admin();
        $accommodation = Question::where('key', 'accommodation')->first();

        $this->actingAs($admin)
            ->put(route('registration.admin.questions.update', $accommodation), $this->lockedAccommodationUpdate($accommodation))
            ->assertRedirect(route('registration.admin.questions').'#question-'.$accommodation->id);

        $saved = $accommodation->fresh();
        $this->assertSame(['Lodging', 'Pick one.'], [$saved->label, $saved->help_text]);
        $this->assertSame(['accommodation', QuestionType::Radio, true, $accommodation->section_id], [$saved->key, $saved->type, $saved->required, $saved->section_id]);
        $this->assertSame(
            [['Hotel room', 'Hotel room', 'Shared', '100.00'], ['none', 'none', null, '0.00']],
            $saved->options()->orderBy('position')->get()->map(fn ($o) => [$o->value, $o->label, $o->description, $o->cost])->all(),
        );
        $this->assertSame(['Hotel room'], $saved->answers()->pluck('value')->unique()->values()->all());
    }

    #[TestDox('a locked update preview counts the stored answers it would change without saving')]
    public function test_a_locked_update_preview_counts_the_stored_answers_it_would_change_without_saving(): void
    {
        $admin = $this->admin();
        $accommodation = Question::where('key', 'accommodation')->first();

        $this->actingAs($admin)
            ->put(route('registration.admin.questions.update', $accommodation), $this->lockedAccommodationUpdate($accommodation, ['preview' => '1']))
            ->assertOk()
            ->assertExactJson(['changes' => 2]);

        $this->assertSame('Accommodation', $accommodation->fresh()->label);
        $this->assertSame(['hotel'], $accommodation->answers()->pluck('value')->unique()->values()->all());
    }

    #[DataProvider('ignoredLockedRows')]
    #[TestDox('a locked option row with a blank value or no matching option is ignored')]
    public function test_a_locked_option_row_with_a_blank_value_or_no_matching_option_is_ignored(bool $known, string $value): void
    {
        $admin = $this->admin();
        $accommodation = Question::where('key', 'accommodation')->first();
        $hotel = $accommodation->options()->where('value', 'hotel')->first();

        $this->actingAs($admin)->put(route('registration.admin.questions.update', $accommodation), [
            'label' => 'Accommodation',
            'options' => [['id' => $known ? $hotel->id : 999999, 'value' => $value, 'label' => 'Ignored']],
        ])->assertRedirect();

        $this->assertSame(['hotel', 'Hotel'], [$hotel->fresh()->value, $hotel->fresh()->label]);
        $this->assertSame(2, $accommodation->options()->count());
    }

    /** Rows a locked save skips, for the data provider. */
    public static function ignoredLockedRows(): array
    {
        return [
            'blank value' => [true, ''],
            'unknown option' => [false, 'Ritz'],
        ];
    }

    #[TestDox('a locked update of a protected question saves its texts but never its options')]
    public function test_a_locked_update_of_a_protected_question_saves_its_texts_but_never_its_options(): void
    {
        $admin = $this->admin();
        $trigger = Question::where('key', Question::GUEST_TRIGGER_KEY)->firstOrFail();
        $option = $trigger->options()->first();

        $this->actingAs($admin)->put(route('registration.admin.questions.update', $trigger), [
            'label' => 'Bringing anyone?',
            'options' => [['id' => $option->id, 'value' => 'Renamed']],
        ])->assertRedirect();

        $this->assertSame('Bringing anyone?', $trigger->fresh()->label);
        $this->assertNotSame('Renamed', $option->fresh()->value);
    }

    #[TestDox('a locked save swapping option values is refused with a message on the form')]
    public function test_a_locked_save_swapping_option_values_is_refused_with_a_message_on_the_form(): void
    {
        $admin = $this->admin();
        $accommodation = Question::where('key', 'accommodation')->first();
        $hotel = $accommodation->options()->where('value', 'hotel')->first();
        $none = $accommodation->options()->where('value', 'none')->first();
        $edit = route('registration.admin.questions.edit', $accommodation);

        $this->actingAs($admin)->from($edit)
            ->put(route('registration.admin.questions.update', $accommodation), [
                'label' => 'Accommodation',
                'options' => [['id' => $hotel->id, 'value' => 'none'], ['id' => $none->id, 'value' => 'hotel']],
            ])
            ->assertRedirect($edit)
            ->assertSessionHasErrors('options');

        $this->assertSame(['hotel', 'none'], [$hotel->fresh()->value, $none->fresh()->value]);

        $this->actingAs($admin)->get($edit)
            ->assertOk()
            ->assertSee(__('registration::admin.options_value_swap'));
    }

    #[TestDox('a failed locked save shows the submitted option texts again')]
    public function test_a_failed_locked_save_shows_the_submitted_option_texts_again(): void
    {
        $admin = $this->admin();
        $accommodation = Question::where('key', 'accommodation')->first();
        $edit = route('registration.admin.questions.edit', $accommodation);

        $this->actingAs($admin)->from($edit)
            ->put(route('registration.admin.questions.update', $accommodation), $this->lockedAccommodationUpdate($accommodation, ['label' => '']))
            ->assertRedirect($edit)
            ->assertSessionHasErrors('label');

        $this->actingAs($admin)->get($edit)
            ->assertOk()
            ->assertSee('name="options[0][value]" value="Hotel room"', false)
            ->assertSee('— 100.00', false);
    }

    #[TestDox('destroy question is blocked while locked')]
    public function test_destroy_question_is_blocked_while_locked(): void
    {
        $admin = $this->admin();
        $nickname = Question::where('key', 'nickname')->first();

        $this->actingAs($admin)
            ->delete(route('registration.admin.questions.destroy', $nickname))
            ->assertRedirect(route('registration.admin.questions'))
            ->assertSessionHas('questions_error');

        $this->assertNotNull(Question::where('key', 'nickname')->first());
    }

    #[TestDox('store option is blocked while locked')]
    public function test_store_option_is_blocked_while_locked(): void
    {
        $admin = $this->admin();
        $gender = Question::where('key', 'gender')->first();
        $before = $gender->options()->count();

        $this->actingAs($admin)
            ->postJson(route('registration.admin.questions.options.store', $gender), ['line' => 'nb | Non-binary'])
            ->assertStatus(423);

        $this->assertSame($before, $gender->options()->count());
    }

    #[TestDox('reorder applies section positions but skips question positions while locked')]
    public function test_reorder_applies_section_positions_but_skips_question_positions_while_locked(): void
    {
        $admin = $this->admin();
        $details = Section::where('key', 'your-details')->first();
        $accommodation = Section::where('key', 'accommodation')->first();
        $nickname = Question::where('key', 'nickname')->first();
        $originalSectionId = $nickname->section_id;
        $originalPosition = $nickname->position;

        $this->actingAs($admin)->postJson(route('registration.admin.questions.reorder'), [
            'sections' => [
                ['id' => $details->id, 'position' => 1],
                ['id' => $accommodation->id, 'position' => 0],
            ],
            'questions' => [
                ['id' => $nickname->id, 'section_id' => $accommodation->id, 'position' => 5],
            ],
        ])->assertOk()->assertJson(['status' => 'ok']);

        // Sections aren't locked — their reorder still applies.
        $this->assertSame(0, $accommodation->fresh()->position);
        $this->assertSame(1, $details->fresh()->position);
        // Questions are locked — the attempted move is silently ignored.
        $nickname->refresh();
        $this->assertSame($originalSectionId, $nickname->section_id);
        $this->assertSame($originalPosition, $nickname->position);
    }

    #[TestDox('update section is blocked while locked')]
    public function test_update_section_is_blocked_while_locked(): void
    {
        $admin = $this->admin();
        $section = Section::where('key', 'your-details')->first();

        $this->actingAs($admin)
            ->put(route('registration.admin.sections.update', $section), ['title' => 'Changed while locked'])
            ->assertRedirect(route('registration.admin.questions'))
            ->assertSessionHas('questions_error');

        $this->assertNotSame('Changed while locked', $section->fresh()->title);
    }

    #[TestDox('index and forms expose the locked flag')]
    public function test_index_and_forms_expose_the_locked_flag(): void
    {
        $unlocked = $this->actingAs($this->makeUser())
            ->get(route('registration.admin.questions'))
            ->assertOk();
        $unlocked->assertSee(__('registration::admin.edit'));
        $unlocked->assertDontSee(__('registration::admin.view'));

        // Renaming a section is locked like editing a question, but without a
        // read-only counterpart: the card header falls back to plain text.
        $section = Section::where('key', 'your-details')->first();
        $unlocked->assertSee('action="'.route('registration.admin.sections.update', $section).'" class="js-section-title', false);

        $admin = $this->admin();
        $locked = $this->actingAs($admin)
            ->get(route('registration.admin.questions'))
            ->assertOk();
        $locked->assertSee(__('registration::admin.edit'));
        $locked->assertSee('id="answer-sync-modal"', false);
        $locked->assertDontSee('action="'.route('registration.admin.sections.update', $section).'" class="js-section-title', false);
        $locked->assertSee('<strong>Your details</strong>', false);

        // An existing question's texts stay editable; adding one stays locked.
        $gender = Question::where('key', 'gender')->first();
        $this->actingAs($admin)
            ->get(route('registration.admin.questions.edit', $gender))
            ->assertOk()
            ->assertSee(__('registration::admin.questions_texts_only'))
            ->assertSee('class="js-answer-sync"', false)
            ->assertSee('name="label" id="label" rows="3" maxlength="4096" class="form-control " required >', false)
            ->assertSee('name="options[0][value]" value="m"', false)
            ->assertSee('name="key" id="key" class="form-control " value="gender" disabled', false)
            ->assertDontSee('name="options[0][line]"', false);

        $this->actingAs($admin)
            ->get(route('registration.admin.questions.create', ['section' => $gender->section_id]))
            ->assertOk()
            ->assertDontSee(__('registration::admin.questions_texts_only'))
            ->assertSee('name="label" id="label" rows="3" maxlength="4096" class="form-control " required disabled', false);
    }
}
