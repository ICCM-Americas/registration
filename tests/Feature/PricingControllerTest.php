<?php

namespace ConferenceTools\Registration\Tests\Feature;

use ConferenceTools\Registration\Enums\PerDiemMode;
use ConferenceTools\Registration\Models\BaseCharge;
use ConferenceTools\Registration\Models\Currency;
use ConferenceTools\Registration\Models\DiscountCode;
use ConferenceTools\Registration\Services\PerDiem;
use ConferenceTools\Registration\Tests\Concerns\BuildsRegistrationData;
use ConferenceTools\Registration\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/** Feature tests for Pricing Controller. */
#[TestDox('Pricing Controller')]
class PricingControllerTest extends TestCase
{
    use BuildsRegistrationData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->allowRegistrationManagement();
    }

    #[TestDox('pricing page requires the gate')]
    public function test_pricing_page_requires_the_gate(): void
    {
        $this->denyRegistrationManagement();

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.pricing'))
            ->assertForbidden();
    }

    #[TestDox('index renders the sections')]
    public function test_index_renders_the_sections(): void
    {
        $this->defaultCurrency();

        $this->actingAs($this->makeUser())
            ->get(route('registration.admin.pricing'))
            ->assertOk()
            ->assertSee('Currencies')
            ->assertSee(__('registration::admin.base_charges'))
            ->assertSee(__('registration::admin.per_diem'))
            ->assertSee(__('registration::admin.discount_codes'));
    }

    #[TestDox('per diem settings are saved')]
    public function test_per_diem_settings_are_saved(): void
    {
        $this->actingAs($this->makeUser())
            ->put(route('registration.admin.pricing.per_diem.update'), [
                'rate_attendee' => '40', 'rate_guest_adult' => '30', 'rate_guest_minor' => '20', 'conference_days' => '5',
                'mode' => PerDiemMode::AllDays->value,
            ])->assertRedirect(route('registration.admin.pricing'));

        $perDiem = app(PerDiem::class);
        $this->assertSame(40.0, $perDiem->attendeeRate());
        $this->assertSame(30.0, $perDiem->guestAdultRate());
        $this->assertSame(20.0, $perDiem->guestMinorRate());
        $this->assertSame(5, $perDiem->conferenceDays());
        $this->assertSame(PerDiemMode::AllDays, $perDiem->mode());
    }

    #[TestDox('per diem rejects an unknown mode')]
    public function test_per_diem_rejects_an_unknown_mode(): void
    {
        $this->actingAs($this->makeUser())
            ->put(route('registration.admin.pricing.per_diem.update'), [
                'mode' => 'sometimes',
            ])->assertSessionHasErrors('mode');
    }

    #[TestDox('currency crud')]
    public function test_currency_crud(): void
    {
        $this->defaultCurrency(); // USD, default

        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.pricing.currencies.store'), [
                'code' => 'eur', 'name' => 'Euro', 'symbol' => '€', 'rate' => '0.9', 'enabled' => '1',
            ])->assertRedirect(route('registration.admin.pricing'));

        $eur = Currency::find('EUR');
        $this->assertNotNull($eur);
        $this->assertTrue($eur->enabled);
        $this->assertFalse($eur->def);

        $this->actingAs($this->makeUser())
            ->put(route('registration.admin.pricing.currencies.update', 'EUR'), [
                'name' => 'Euros', 'symbol' => '€', 'rate' => '0.95',
            ]);
        $this->assertSame('Euros', Currency::find('EUR')->name);
        $this->assertFalse(Currency::find('EUR')->enabled); // checkbox unchecked

        $this->actingAs($this->makeUser())
            ->delete(route('registration.admin.pricing.currencies.destroy', 'EUR'));
        $this->assertNull(Currency::find('EUR'));
    }

    #[TestDox('only one currency stays default')]
    public function test_only_one_currency_stays_default(): void
    {
        $this->defaultCurrency(); // USD def=true

        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.pricing.currencies.store'), [
                'code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'rate' => '0.9', 'def' => '1', 'enabled' => '1',
            ]);

        $this->assertTrue(Currency::find('EUR')->def);
        $this->assertFalse(Currency::find('USD')->def);
        $this->assertSame(1, Currency::where('def', true)->count());
    }

    #[TestDox('default currency cannot be deleted')]
    public function test_default_currency_cannot_be_deleted(): void
    {
        $this->defaultCurrency(); // USD def=true

        $this->actingAs($this->makeUser())
            ->delete(route('registration.admin.pricing.currencies.destroy', 'USD'))
            ->assertSessionHas('pricing_error');

        $this->assertNotNull(Currency::find('USD'));
    }

    #[TestDox('base charge crud')]
    public function test_base_charge_crud(): void
    {
        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.pricing.charges.store'), [
                'name' => 'Registration fee', 'amount' => '150', 'enabled' => '1',
            ])->assertRedirect(route('registration.admin.pricing'));

        $charge = BaseCharge::firstWhere('name', 'Registration fee');
        $this->assertNotNull($charge);
        $this->assertEquals(150, (float) $charge->amount);

        $this->actingAs($this->makeUser())
            ->put(route('registration.admin.pricing.charges.update', $charge), [
                'name' => 'Registration fee', 'amount' => '175',
            ]);
        $this->assertEquals(175, (float) $charge->fresh()->amount);
        $this->assertFalse($charge->fresh()->enabled);

        $this->actingAs($this->makeUser())
            ->delete(route('registration.admin.pricing.charges.destroy', $charge));
        $this->assertSame(0, BaseCharge::count());
    }

    #[TestDox('discount code crud')]
    public function test_discount_code_crud(): void
    {
        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.pricing.discounts.store'), [
                'code' => 'K7QND2', 'formula' => '-50', 'description' => 'A discount', 'enabled' => '1',
            ])->assertRedirect(route('registration.admin.pricing'));

        $code = DiscountCode::firstWhere('code', 'K7QND2');
        $this->assertNotNull($code);
        $this->assertSame('-50', $code->formula);

        $this->actingAs($this->makeUser())
            ->put(route('registration.admin.pricing.discounts.update', $code), [
                'code' => 'K7QND2', 'formula' => '-25', 'description' => 'Cheaper', 'enabled' => '0',
            ])->assertRedirect(route('registration.admin.pricing'));
        $code->refresh();
        $this->assertSame('-25', $code->formula);
        $this->assertFalse((bool) $code->enabled);

        $this->actingAs($this->makeUser())
            ->delete(route('registration.admin.pricing.discounts.destroy', $code));
        $this->assertSame(0, DiscountCode::count());
    }

    #[DataProvider('invalidFormulas')]
    #[TestDox('discount formula validation rejects bad forms')]
    public function test_discount_formula_validation_rejects_bad_forms(string $formula): void
    {
        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.pricing.discounts.store'), [
                'code' => 'X9FJ4M', 'formula' => $formula,
            ])->assertSessionHasErrors('formula');

        $this->assertSame(0, DiscountCode::count());
    }

    /** The invalid formulas for the data provider. */
    public static function invalidFormulas(): array
    {
        return [
            'mixed op and percent' => ['+5%'],
            'percent with op' => ['*10%'],
            'bare number' => ['50'],
            'letters' => ['abc'],
            'double op' => ['+-5'],
            'trailing text' => ['-50 off'],
        ];
    }

    #[DataProvider('validFormulas')]
    #[TestDox('discount formula validation accepts each form')]
    public function test_discount_formula_validation_accepts_each_form(string $formula): void
    {
        $this->actingAs($this->makeUser())
            ->post(route('registration.admin.pricing.discounts.store'), [
                'code' => 'B3LRZ8', 'formula' => $formula, 'enabled' => '1',
            ])->assertSessionHasNoErrors();

        $this->assertSame(1, DiscountCode::count());
    }

    /** The valid formulas for the data provider. */
    public static function validFormulas(): array
    {
        return [['+25'], ['-50'], ['=0'], ['*0.5'], ['90%'], ['12.5%']];
    }
}
