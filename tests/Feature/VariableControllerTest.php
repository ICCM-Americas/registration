<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Models\Variable;
use ConferenceTools\Registration\Tests\Concerns\BuildsRegistrationData;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/** Feature tests for Variable Controller. */
#[TestDox('Variable Controller')]
class VariableControllerTest extends TestCase
{
    use BuildsRegistrationData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allowRegistrationManagement();
    }

    #[TestDox('variables page requires the gate')]
    public function test_variables_page_requires_the_gate(): void
    {
        $this->denyRegistrationManagement();

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.variables'))
            ->assertForbidden();
    }

    #[TestDox('index lists each variable as a copyable token')]
    public function test_index_lists_each_variable_as_a_copyable_token(): void
    {
        Variable::factory()->create(['name' => 'conf_name', 'value' => 'ICCM Africa']);

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.variables'))
            ->assertOk()
            ->assertSee('{conf_name}')
            ->assertSee('ICCM Africa');
    }

    #[TestDox('variable crud and the name is immutable')]
    public function test_variable_crud_and_the_name_is_immutable(): void
    {
        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.variables.store'), [
                'name' => 'conf_fee', 'value' => '100 USD',
            ])->assertRedirect(route('registration.admin.variables'));

        $variable = Variable::firstWhere('name', 'conf_fee');
        $this->assertNotNull($variable);
        $this->assertSame('100 USD', $variable->value);

        // Only the value is editable; a submitted name is ignored so existing
        // {conf_fee} tokens keep resolving.
        $this->actingAs($this->makeUser())
            ->put(route('registration.admin.variables.update', $variable), [
                'name' => 'renamed', 'value' => '125 USD',
            ])->assertRedirect(route('registration.admin.variables'));
        $variable->refresh();
        $this->assertSame('conf_fee', $variable->name);
        $this->assertSame('125 USD', $variable->value);

        $this->actingAs($this->makeUser())
            ->delete(route('registration.admin.variables.destroy', $variable));
        $this->assertSame(0, Variable::count());
    }

    #[DataProvider('invalidVariablePayloads')]
    #[TestDox('store validation rejects bad input')]
    public function test_store_validation_rejects_bad_input(array $payload, string $field): void
    {
        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.variables.store'), $payload)
            ->assertSessionHasErrors($field);

        $this->assertSame(0, Variable::count());
    }

    /** The invalid variable payloads for the data provider. */
    public static function invalidVariablePayloads(): array
    {
        $valid = ['name' => 'conf_name', 'value' => 'ICCM Africa'];

        return [
            'missing name' => [array_diff_key($valid, ['name' => true]), 'name'],
            'missing value' => [array_diff_key($valid, ['value' => true]), 'value'],
            'name starting with a digit' => [['name' => '1abc'] + $valid, 'name'],
            'name with a space' => [['name' => 'conf name'] + $valid, 'name'],
            'name with a dash' => [['name' => 'conf-name'] + $valid, 'name'],
            'name with braces' => [['name' => '{conf}'] + $valid, 'name'],
            'name over 64 characters' => [['name' => str_repeat('a', 65)] + $valid, 'name'],
        ];
    }

    #[TestDox('store rejects a duplicate name')]
    public function test_store_rejects_a_duplicate_name(): void
    {
        Variable::factory()->create(['name' => 'conf_name']);

        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.variables.store'), [
                'name' => 'conf_name', 'value' => 'Second',
            ])->assertSessionHasErrors('name');

        $this->assertSame(1, Variable::count());
    }
}
