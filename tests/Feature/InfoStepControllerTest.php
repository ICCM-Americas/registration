<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Models\InfoStep;
use ConferenceTools\Registration\Tests\Concerns\BuildsRegistrationData;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/** Feature tests for Info Step Controller. */
#[TestDox('Info Step Controller')]
class InfoStepControllerTest extends TestCase
{
    use BuildsRegistrationData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allowRegistrationManagement();
    }

    #[TestDox('landing page console requires the gate')]
    public function test_landing_page_console_requires_the_gate(): void
    {
        $this->denyRegistrationManagement();

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.steps'))
            ->assertForbidden();
    }

    #[TestDox('index lists the steps in position order with their controls')]
    public function test_index_lists_the_steps_in_position_order_with_their_controls(): void
    {
        InfoStep::factory()->create(['heading' => 'Payment', 'position' => 2]);
        $hidden = InfoStep::factory()->disabled()->create(['heading' => 'Log in', 'position' => 1]);

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.steps'))
            ->assertOk()
            ->assertSeeInOrder(['Log in', 'Payment'])
            // Builder-style rows: drag list + per-step Edit / hide-show /
            // Translations / Delete controls; a hidden step is badged.
            ->assertSee('Sortable.min.js', false)
            ->assertSee(route('registration.admin.steps.reorder'))
            ->assertSee(route('registration.admin.steps.edit', $hidden))
            ->assertSee(route('registration.admin.steps.show', $hidden))
            ->assertSee(route('registration.admin.translations', ['step', $hidden->id]))
            ->assertSee(__('registration::admin.badge_hidden'));
    }

    #[TestDox('step rows carry a toggleable translated tag')]
    public function test_step_rows_carry_a_toggleable_translated_tag(): void
    {
        // The tag stays in the DOM (d-none when off) so the translations
        // modal can toggle it without a page reload.
        $translated = InfoStep::factory()->create(['heading' => 'Payment']);
        $translated->storeTranslation('fr', 'heading', 'Paiement');
        $plain = InfoStep::factory()->create(['heading' => 'Log in']);

        $html = $this->actingAs($this->makeUser())
            ->get(route('registration.admin.steps'))
            ->assertOk()
            ->getContent();

        $translatedRow = Str::before(Str::after($html, sprintf('data-step-id="%d"', $translated->id)), '</li>');
        $this->assertStringContainsString('ml-1" data-badge="translated"', $translatedRow);

        $plainRow = Str::before(Str::after($html, sprintf('data-step-id="%d"', $plain->id)), '</li>');
        $this->assertStringContainsString('d-none" data-badge="translated"', $plainRow);
    }

    #[TestDox('create and edit forms render')]
    public function test_create_and_edit_forms_render(): void
    {
        $step = InfoStep::factory()->create(['heading' => 'Payment']);

        // A not-yet-saved step has no row to translate, so no button.
        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.steps.create'))
            ->assertOk()
            ->assertSee(__('registration::admin.add_step'))
            ->assertDontSee('js-editor-link');

        // Editing an existing step exposes the shared translations modal.
        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.steps.edit', $step))
            ->assertOk()
            ->assertSee('Payment')
            ->assertSee(route('registration.admin.translations', ['step', $step->id]))
            ->assertSee('js-editor-link');
    }

    #[TestDox('step crud')]
    public function test_step_crud(): void
    {
        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.steps.store'), [
                'heading' => 'Payment', 'body' => 'Pay after the form.',
            ])->assertRedirect(route('registration.admin.steps'));

        $step = InfoStep::firstWhere('heading', 'Payment');
        $this->assertNotNull($step);
        $this->assertSame('Pay after the form.', $step->body);
        $this->assertSame(1, $step->position);
        $this->assertTrue($step->enabled);

        $this->actingAs($this->makeUser())
            ->put(route('registration.admin.steps.update', $step), [
                'heading' => 'Check payment', 'body' => 'Post us a check.',
            ])->assertRedirect(route('registration.admin.steps'));
        $step->refresh();
        $this->assertSame('Check payment', $step->heading);
        $this->assertSame('Post us a check.', $step->body);

        $this->actingAs($this->makeUser())
            ->delete(route('registration.admin.steps.destroy', $step));
        $this->assertSame(0, InfoStep::count());
    }

    #[TestDox('new steps are appended after the last position')]
    public function test_new_steps_are_appended_after_the_last_position(): void
    {
        InfoStep::factory()->create(['position' => 7]);

        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.steps.store'), ['heading' => 'Extras', 'body' => 'Optional extras.']);

        $this->assertSame(8, InfoStep::firstWhere('heading', 'Extras')->position);
    }

    #[TestDox('reorder persists the dragged arrangement')]
    public function test_reorder_persists_the_dragged_arrangement(): void
    {
        $first = InfoStep::factory()->create(['position' => 1]);
        $second = InfoStep::factory()->create(['position' => 2]);

        $this->actingAs($this->makeUser())
            ->postJson(route('registration.admin.steps.reorder'), [
                'steps' => [
                    ['id' => $second->id, 'position' => 0],
                    ['id' => $first->id, 'position' => 1],
                ],
            ])->assertOk()->assertJson(['status' => 'ok']);

        $this->assertSame(0, $second->fresh()->position);
        $this->assertSame(1, $first->fresh()->position);
    }

    #[TestDox('reorder rejects a malformed payload')]
    public function test_reorder_rejects_a_malformed_payload(): void
    {
        $this->actingAs($this->makeUser())
            ->postJson(route('registration.admin.steps.reorder'), [
                'steps' => [['id' => 'x']],
            ])->assertUnprocessable();
    }

    #[TestDox('hide and show toggle a step')]
    public function test_hide_and_show_toggle_a_step(): void
    {
        $step = InfoStep::factory()->create();

        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.steps.hide', $step))
            ->assertRedirect(route('registration.admin.steps'));
        $this->assertFalse($step->fresh()->enabled);

        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.steps.show', $step))
            ->assertRedirect(route('registration.admin.steps'));
        $this->assertTrue($step->fresh()->enabled);
    }

    #[DataProvider('invalidStepPayloads')]
    #[TestDox('step validation rejects bad input')]
    public function test_step_validation_rejects_bad_input(array $payload, string $field): void
    {
        $step = InfoStep::factory()->create();

        $this->actingAs($this->makeUser())
            ->put(route('registration.admin.steps.update', $step), $payload)
            ->assertSessionHasErrors($field);
    }

    /** The invalid step payloads for the data provider. */
    public static function invalidStepPayloads(): array
    {
        $valid = ['heading' => 'Payment', 'body' => 'Pay after the form.'];

        return [
            'missing heading' => [array_diff_key($valid, ['heading' => true]), 'heading'],
            'missing body' => [array_diff_key($valid, ['body' => true]), 'body'],
            'overlong heading' => [['heading' => str_repeat('a', 256)] + $valid, 'heading'],
        ];
    }
}
