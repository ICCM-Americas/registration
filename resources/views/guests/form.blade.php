@extends(config('registration.layout'))

@section('title')
{{ $guestType === null ? __('registration::common.guest_add') : __('registration::common.guest_edit') }}
@endsection

@php
    // Literal per-key calls (a concatenated key would not be seen by the
    // translation-coverage guard — see pricing/index.blade.php's
    // $perDiemModeLabels for the same pattern).
    $guestTypeLabels = [
        'adult' => __('registration::common.guest_type_adult'),
        'minor' => __('registration::common.guest_type_minor'),
    ];
@endphp

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-8">
            @include('registration::partials.test-mode-banner')

            <div class="card">
                <div class="card-header">
                    {{ $guestType === null ? __('registration::common.guest_add') : __('registration::common.guest_edit') }}
                </div>

                <div class="card-body">
                    <form id="registration-form" method="POST" action="{{ $action }}">
                        @csrf

                        @if ($guestType === null)
                            <div class="form-group" data-question="guest_type">
                                <label>
                                    {{ __('registration::common.guest_type_label') }} *
                                </label>
                                @foreach (\ConferenceTools\Registration\Enums\GuestType::cases() as $type)
                                    <div class="form-check">
                                        <input type="radio" id="guest_type_{{ $type->value }}" name="guest_type" value="{{ $type->value }}"
                                            class="form-check-input {{ $errors->has('guest_type') ? 'is-invalid' : '' }}" required>
                                        <label class="form-check-label" for="guest_type_{{ $type->value }}">
                                            {{ $guestTypeLabels[$type->value] }}
                                        </label>
                                    </div>
                                @endforeach
                                @error('guest_type')
                                    <span class="invalid-feedback d-block" role="alert"><strong>{{ $message }}</strong></span>
                                @enderror
                            </div>
                        @else
                            {{-- The type is fixed on edit, so it's shown as
                                 plain text — but a guest_type-conditioned
                                 question's live toggle (see
                                 partials/visibility-toggle) reads every
                                 answer straight from the DOM by input name,
                                 so this hidden mirror is what lets it see the
                                 fixed type too, not just the create form's
                                 radios. --}}
                            <input type="hidden" name="guest_type" value="{{ $guestType->value }}">
                            <div class="form-group">
                                <label>
                                    {{ __('registration::common.guest_type_label') }}
                                </label>
                                <div>
                                    {{ $guestTypeLabels[$guestType->value] }}
                                </div>
                            </div>
                        @endif

                        @foreach ($sections as $section)
                            @include('registration::questions.section', compact('section', 'answers', 'evaluator', 'def'))
                        @endforeach

                        <div class="d-flex justify-content-between mt-3">
                            <a href="{{ route($routeName($routePrefix)) }}" class="btn btn-outline-secondary">
                                {{ __('Back') }}
                            </a>
                            <button type="submit" class="btn btn-primary">
                                {{ __('registration::common.guest_save') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

@include('registration::partials.visibility-toggle')
@include('registration::partials.yesno-toggle')
@endsection
