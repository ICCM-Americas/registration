@extends(config('registration.layout'))

@section('title')
{{ __('registration::admin.pricing_title') }}
@endsection

@php
    // Literal per-key strings (a concatenated lang key would not be seen by
    // the translation-coverage guard).
    $perDiemModeLabels = [
        'extra_only' => __('registration::admin.per_diem_mode_extra_only'),
        'all_days' => __('registration::admin.per_diem_mode_all_days'),
    ];
@endphp

@section('content')
    @include('registration::partials.admin-nav')


    <h1>{{ __('registration::admin.pricing_title') }}</h1>
    <p class="text-muted">{{ __('registration::admin.pricing_intro') }}</p>

    @if (session('pricing_error'))
        <div class="alert alert-danger">{{ session('pricing_error') }}</div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Currencies --------------------------------------------------------- --}}
    <div class="card mb-4">
        <div class="card-header">{{ __('registration::admin.currencies') }}</div>
        <div class="card-body">
            <p class="text-muted">{{ __('registration::admin.currencies_intro') }}</p>

            @foreach ($currencies as $currency)
                <div class="iccm-row">
                    <form method="POST" action="{{ route($routeName('admin.pricing.currencies.update'), $currency->code) }}" class="iccm-row iccm-row-tight">
                        @csrf
                        @method('PUT')
                        <strong class="iccm-field-3">{{ $currency->code }}</strong>
                        <input type="text" name="name" value="{{ $currency->name }}" class="form-control form-control-sm iccm-field-10" required>
                        <input type="text" name="symbol" value="{{ $currency->symbol }}" class="form-control form-control-sm iccm-field-4" required>
                        <input type="number" step="0.00000001" min="0" name="rate" value="{{ $currency->rate }}" class="form-control form-control-sm iccm-field-8" required>
                        <label class="mb-0"><input type="checkbox" name="def" value="1" {{ $currency->def ? 'checked' : '' }}> {{ __('registration::admin.default') }}</label>
                        <label class="mb-0"><input type="checkbox" name="enabled" value="1" {{ $currency->enabled ? 'checked' : '' }}> {{ __('registration::admin.enabled') }}</label>
                        <button type="submit" class="btn btn-sm btn-primary">{{ __('registration::admin.save') }}</button>
                    </form>
                    <form method="POST" action="{{ route($routeName('admin.pricing.currencies.destroy'), $currency->code) }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-sm btn-outline-danger">{{ __('registration::admin.delete') }}</button>
                    </form>
                </div>
            @endforeach

            <form method="POST" action="{{ route($routeName('admin.pricing.currencies.store')) }}" class="iccm-row iccm-row-spaced">
                @csrf
                <input type="text" name="code" maxlength="3" placeholder="{{ __('registration::admin.code') }}" class="form-control form-control-sm iccm-field-5" required>
                <input type="text" name="name" placeholder="{{ __('registration::admin.name') }}" class="form-control form-control-sm iccm-field-10" required>
                <input type="text" name="symbol" placeholder="{{ __('registration::admin.symbol') }}" class="form-control form-control-sm iccm-field-4" required>
                <input type="number" step="0.00000001" min="0" name="rate" value="1" class="form-control form-control-sm iccm-field-8" required>
                <label class="mb-0"><input type="checkbox" name="def" value="1"> {{ __('registration::admin.default') }}</label>
                <label class="mb-0"><input type="checkbox" name="enabled" value="1" checked> {{ __('registration::admin.enabled') }}</label>
                <button type="submit" class="btn btn-sm btn-secondary">{{ __('registration::admin.add') }}</button>
            </form>
        </div>
    </div>

    {{-- Base charges ------------------------------------------------------- --}}
    <div class="card mb-4">
        <div class="card-header">{{ __('registration::admin.base_charges') }}</div>
        <div class="card-body">
            <p class="text-muted">{{ __('registration::admin.base_charges_intro') }}</p>

            @foreach ($charges as $charge)
                <div class="iccm-row">
                    <form method="POST" action="{{ route($routeName('admin.pricing.charges.update'), $charge) }}" class="iccm-row iccm-row-tight">
                        @csrf
                        @method('PUT')
                        <input type="text" name="name" value="{{ $charge->name }}" class="form-control form-control-sm iccm-field-14" required>
                        <input type="number" step="0.01" min="0" name="amount" value="{{ $charge->amount }}" class="form-control form-control-sm iccm-field-8" required>
                        <label class="mb-0"><input type="checkbox" name="enabled" value="1" {{ $charge->enabled ? 'checked' : '' }}> {{ __('registration::admin.enabled') }}</label>
                        <button type="submit" class="btn btn-sm btn-primary">{{ __('registration::admin.save') }}</button>
                    </form>
                    <form method="POST" action="{{ route($routeName('admin.pricing.charges.destroy'), $charge) }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-sm btn-outline-danger">{{ __('registration::admin.delete') }}</button>
                    </form>
                </div>
            @endforeach

            <form method="POST" action="{{ route($routeName('admin.pricing.charges.store')) }}" class="iccm-row iccm-row-spaced">
                @csrf
                <input type="text" name="name" placeholder="{{ __('registration::admin.name') }}" class="form-control form-control-sm iccm-field-14" required>
                <input type="number" step="0.01" min="0" name="amount" value="0" class="form-control form-control-sm iccm-field-8" required>
                <label class="mb-0"><input type="checkbox" name="enabled" value="1" checked> {{ __('registration::admin.enabled') }}</label>
                <button type="submit" class="btn btn-sm btn-secondary">{{ __('registration::admin.add') }}</button>
            </form>
        </div>
    </div>

    {{-- Per diem ------------------------------------------------------------ --}}
    <div class="card mb-4">
        <div class="card-header">{{ __('registration::admin.per_diem') }}</div>
        <div class="card-body">
            <p class="text-muted">{{ __('registration::admin.per_diem_intro') }}</p>

            <form method="POST" action="{{ route($routeName('admin.pricing.per_diem.update')) }}">
                @csrf
                @method('PUT')

                <div class="iccm-row">
                    <label class="mb-0">{{ __('registration::admin.per_diem_rate_attendee') }}
                        <input type="number" step="0.01" min="0" name="rate_attendee" value="{{ $perDiem->attendeeRate() }}" class="form-control form-control-sm iccm-field-8">
                    </label>
                    <label class="mb-0">{{ __('registration::admin.per_diem_rate_guest_adult') }}
                        <input type="number" step="0.01" min="0" name="rate_guest_adult" value="{{ $perDiem->guestAdultRate() }}" class="form-control form-control-sm iccm-field-8">
                    </label>
                    <label class="mb-0">{{ __('registration::admin.per_diem_rate_guest_minor') }}
                        <input type="number" step="0.01" min="0" name="rate_guest_minor" value="{{ $perDiem->guestMinorRate() }}" class="form-control form-control-sm iccm-field-8">
                    </label>
                    <label class="mb-0">{{ __('registration::admin.per_diem_conference_days') }}
                        <input type="number" step="1" min="0" name="conference_days" value="{{ $perDiem->conferenceDays() }}" class="form-control form-control-sm iccm-field-6">
                    </label>
                </div>

                <fieldset class="mb-3 pricing-per-diem-fieldset">
                    <legend class="h6 pricing-per-diem-legend">{{ __('registration::admin.per_diem_attendee_mode_legend') }}</legend>
                    <p class="text-muted small mb-2">{{ __('registration::admin.per_diem_mode_intro') }}</p>
                    <label class="mb-0">{{ __('registration::admin.per_diem_mode') }}
                        <select name="mode" class="form-control form-control-sm iccm-field-16">
                            @foreach ($perDiemModes as $mode)
                                <option value="{{ $mode->value }}" {{ $perDiem->mode() === $mode ? 'selected' : '' }}>{{ $perDiemModeLabels[$mode->value] }}</option>
                            @endforeach
                        </select>
                    </label>
                </fieldset>

                <button type="submit" class="btn btn-sm btn-primary">{{ __('registration::admin.save') }}</button>
            </form>
        </div>
    </div>

    {{-- Discount codes ----------------------------------------------------- --}}
    <div class="card mb-4">
        <div class="card-header">{{ __('registration::admin.discount_codes') }}</div>
        <div class="card-body">
            <p class="text-muted">{{ __('registration::admin.discount_codes_intro') }}</p>

            @foreach ($discounts as $discount)
                <div class="iccm-row">
                    <form method="POST" action="{{ route($routeName('admin.pricing.discounts.update'), $discount) }}" class="iccm-row iccm-row-tight">
                        @csrf
                        @method('PUT')
                        <input type="text" name="code" value="{{ $discount->code }}" class="form-control form-control-sm iccm-field-8" required>
                        <input type="text" name="formula" value="{{ $discount->formula }}" class="form-control form-control-sm iccm-field-7" required>
                        <input type="text" name="description" value="{{ $discount->description }}" class="form-control form-control-sm iccm-field-14">
                        <label class="mb-0"><input type="checkbox" name="enabled" value="1" {{ $discount->enabled ? 'checked' : '' }}> {{ __('registration::admin.enabled') }}</label>
                        <button type="submit" class="btn btn-sm btn-primary">{{ __('registration::admin.save') }}</button>
                    </form>
                    <form method="POST" action="{{ route($routeName('admin.pricing.discounts.destroy'), $discount) }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-sm btn-outline-danger">{{ __('registration::admin.delete') }}</button>
                    </form>
                </div>
            @endforeach

            <form method="POST" action="{{ route($routeName('admin.pricing.discounts.store')) }}" class="iccm-row iccm-row-spaced">
                @csrf
                <input type="text" name="code" placeholder="{{ __('registration::admin.code') }}" class="form-control form-control-sm iccm-field-8" required>
                <input type="text" name="formula" placeholder="-50" class="form-control form-control-sm iccm-field-7" required>
                <input type="text" name="description" placeholder="{{ __('registration::admin.description') }}" class="form-control form-control-sm iccm-field-14">
                <label class="mb-0"><input type="checkbox" name="enabled" value="1" checked> {{ __('registration::admin.enabled') }}</label>
                <button type="submit" class="btn btn-sm btn-secondary">{{ __('registration::admin.add') }}</button>
            </form>
        </div>
    </div>
@endsection
