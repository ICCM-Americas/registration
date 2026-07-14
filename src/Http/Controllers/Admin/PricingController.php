<?php

namespace ConferenceTools\Registration\Http\Controllers\Admin;

use ConferenceTools\Registration\Enums\PerDiemMode;
use ConferenceTools\Registration\Http\Controllers\Controller;
use ConferenceTools\Registration\Models\BaseCharge;
use ConferenceTools\Registration\Models\Currency;
use ConferenceTools\Registration\Models\DiscountCode;
use ConferenceTools\Registration\Services\PerDiem;
use ConferenceTools\Registration\Support\DiscountFormula;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The admin "Pricing" console: the cost provisions that used to live only in
 * seeders. Admins define which currencies are allowed (full CRUD plus an enabled
 * toggle), the flat per-registrant base charges, the per-diem (room & board)
 * rates, and the discount codes registrants may enter against a discount-code
 * question.
 *
 * Everything lives on one page; each entity has its own store/update/destroy.
 */
class PricingController extends Controller
{
    /** The pricing console: currencies, base charges, discount codes, per-diem. */
    public function index(PerDiem $perDiem)
    {
        return view('registration::admin.pricing.index', [
            'currencies' => Currency::orderBy('code')->get(),
            'charges' => BaseCharge::orderBy('order')->get(),
            'discounts' => DiscountCode::orderBy('code')->get(),
            'perDiem' => $perDiem,
            'perDiemModes' => PerDiemMode::cases(),
        ]);
    }

    // -- Currencies ----------------------------------------------------------

    /** Add a currency. */
    public function storeCurrency(Request $request)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'size:3', 'alpha', Rule::unique(Currency::make()->getTable(), 'code')],
            'name' => ['required', 'string', 'max:255'],
            'symbol' => ['required', 'string', 'max:8'],
            'rate' => ['required', 'numeric', 'min:0'],
            'def' => ['boolean'],
            'enabled' => ['boolean'],
        ]);

        $data['code'] = strtoupper($data['code']);
        $data['def'] = $request->boolean('def');
        $data['enabled'] = $request->boolean('enabled');

        DB::transaction(function () use ($data) {
            $currency = Currency::create($data);
            $this->ensureSingleDefault($currency);
        });

        return $this->back();
    }

    /** Save a currency's details. */
    public function updateCurrency(Request $request, Currency $currency)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'symbol' => ['required', 'string', 'max:8'],
            'rate' => ['required', 'numeric', 'min:0'],
            'def' => ['boolean'],
            'enabled' => ['boolean'],
        ]);

        $data['def'] = $request->boolean('def');
        $data['enabled'] = $request->boolean('enabled');

        DB::transaction(function () use ($currency, $data) {
            $currency->update($data);
            $this->ensureSingleDefault($currency);
        });

        return $this->back();
    }

    /** Remove a currency. */
    public function destroyCurrency(Currency $currency)
    {
        // The default (base) currency expresses every price; it cannot be removed.
        if ($currency->def) {
            return $this->back('cannot_delete_default_currency');
        }

        $currency->delete();

        return $this->back();
    }

    // -- Base charges --------------------------------------------------------

    /** Add a base charge. */
    public function storeBaseCharge(Request $request)
    {
        $data = $this->validateBaseCharge($request);
        $data['order'] = (int) BaseCharge::max('order') + 1;
        $data['enabled'] = $request->boolean('enabled');

        BaseCharge::create($data);

        return $this->back();
    }

    /** Save a base charge's details. */
    public function updateBaseCharge(Request $request, BaseCharge $baseCharge)
    {
        $data = $this->validateBaseCharge($request);
        $data['enabled'] = $request->boolean('enabled');

        $baseCharge->update($data);

        return $this->back();
    }

    /** Remove a base charge. */
    public function destroyBaseCharge(BaseCharge $baseCharge)
    {
        $baseCharge->delete();

        return $this->back();
    }

    // -- Discount codes ------------------------------------------------------

    /** Add a discount code. */
    public function storeDiscountCode(Request $request)
    {
        $data = $this->validateDiscountCode($request);
        $data['enabled'] = $request->boolean('enabled');

        DiscountCode::create($data);

        return $this->back();
    }

    /** Save a discount code's details. */
    public function updateDiscountCode(Request $request, DiscountCode $discountCode)
    {
        $data = $this->validateDiscountCode($request);
        $data['enabled'] = $request->boolean('enabled');

        $discountCode->update($data);

        return $this->back();
    }

    /** Remove a discount code. */
    public function destroyDiscountCode(DiscountCode $discountCode)
    {
        $discountCode->delete();

        return $this->back();
    }

    // -- Per diem ------------------------------------------------------------

    /** Save the per-diem rates and mode. */
    public function updatePerDiem(Request $request, PerDiem $perDiem)
    {
        $data = $request->validate([
            'rate_attendee' => ['nullable', 'numeric', 'min:0'],
            'rate_guest_adult' => ['nullable', 'numeric', 'min:0'],
            'rate_guest_minor' => ['nullable', 'numeric', 'min:0'],
            'conference_days' => ['nullable', 'integer', 'min:0'],
            'mode' => ['required', Rule::in(PerDiemMode::values())],
        ]);

        $perDiem->update(
            $data['rate_attendee'] !== null ? (float) $data['rate_attendee'] : null,
            $data['rate_guest_adult'] !== null ? (float) $data['rate_guest_adult'] : null,
            $data['rate_guest_minor'] !== null ? (float) $data['rate_guest_minor'] : null,
            $data['conference_days'] !== null ? (int) $data['conference_days'] : null,
            PerDiemMode::from($data['mode']),
        );

        return $this->back();
    }

    // -- Helpers -------------------------------------------------------------

    /** Validate a base-charge form submission. */
    private function validateBaseCharge(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0'],
            'enabled' => ['boolean'],
        ]);
    }

    /** Validate a discount-code form submission. */
    private function validateDiscountCode(Request $request): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:255'],
            'formula' => ['required', 'string', 'max:255', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! (new DiscountFormula((string) $value))->isValid()) {
                    $fail(__('registration::admin.invalid_formula'));
                }
            }],
            'description' => ['nullable', 'string', 'max:1000'],
            'enabled' => ['boolean'],
        ]);
    }

    /** Keep exactly one default currency: clear "def" on every other row. */
    private function ensureSingleDefault(Currency $currency): void
    {
        if (! $currency->def) {
            return;
        }

        Currency::where('code', '!=', $currency->code)->where('def', true)->update(['def' => false]);
    }

    /** Redirect back to the console with a status message. */
    private function back(?string $error = null)
    {
        $redirect = redirect()->route($this->routeName('admin.pricing'));

        return $error ? $redirect->with('pricing_error', __('registration::admin.'.$error)) : $redirect;
    }
}
