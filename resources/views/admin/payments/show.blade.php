@extends(config('registration.layout'))

@section('title')
{{ $name }}
@endsection

@section('content')
    <p><a href="{{ route($routeName('admin.payments')) }}">&larr; {{ __('registration::admin.payments_back') }}</a></p>

    <h1>{{ $name }}</h1>

    @if (session('payments_status'))
        <div class="alert alert-success">{{ session('payments_status') }}</div>
    @endif

    @include('registration::partials.registration-summary', [
        'registrant' => $registrant,
        'fields' => $fields,
        'guestQuestions' => $guestQuestions,
        'costSummary' => $costSummary,
        'def' => $def,
        'editAnswersUrl' => route($routeName('admin.payments.answers.edit'), $registrant->getKey()),
        'editGuestAnswersUrl' => fn ($guest) => route($routeName('admin.payments.guests.answers.edit'), [$registrant->getKey(), $guest->getKey()]),
    ])

    <div class="card mb-3">
        <div class="card-header"><strong>{{ __('registration::admin.payments_paid_label') }}</strong></div>
        <div class="card-body">
            @include('registration::admin.payments.payment-form', ['registrant' => $registrant])
        </div>
    </div>
@endsection
