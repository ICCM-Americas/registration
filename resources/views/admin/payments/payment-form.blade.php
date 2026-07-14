{{--
    The mark-paid mini-form for one registrant: paid checkbox, amount, notes.
    Shared by the list and the single-registrant view.

    Expects: $registrant (its `payment` relation, loaded or not).
--}}
@php($payment = $registrant->payment)

<form method="POST" action="{{ route($routeName('admin.payments.payment.update'), $registrant->getKey()) }}" class="iccm-row iccm-row-wide iccm-row-tight">
    @csrf
    @method('PUT')
    <div class="form-check">
        <input type="checkbox" class="form-check-input" id="paid_{{ $registrant->getKey() }}" name="is_paid" value="1" {{ $payment?->is_paid ? 'checked' : '' }}>
        <label class="form-check-label" for="paid_{{ $registrant->getKey() }}">{{ __('registration::admin.payments_paid_label') }}</label>
    </div>
    <label for="amount_{{ $registrant->getKey() }}" class="sr-only">{{ __('registration::admin.payments_amount_label') }}</label>
    <input type="number" step="0.01" min="0" class="form-control form-control-sm iccm-field-8" id="amount_{{ $registrant->getKey() }}" name="amount"
        placeholder="{{ __('registration::admin.payments_amount_label') }}" value="{{ old('amount', $payment?->amount) }}">
    <label for="notes_{{ $registrant->getKey() }}" class="sr-only">{{ __('registration::admin.payments_notes_label') }}</label>
    <input type="text" class="form-control form-control-sm payments-field-notes" id="notes_{{ $registrant->getKey() }}" name="notes"
        placeholder="{{ __('registration::admin.payments_notes_label') }}" value="{{ old('notes', $payment?->notes) }}">
    <button type="submit" class="btn btn-sm btn-primary">{{ __('registration::admin.payments_save_payment') }}</button>
</form>
