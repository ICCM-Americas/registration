@extends(config('registration.layout'))

@section('title')
{{ __('registration::admin.payments_title') }}
@endsection

@php
    $money = fn ($amount) => $def?->format($amount) ?? number_format((float) $amount, 2);
    // Literal per-key calls (a concatenated key would not be seen by the
    // translation-coverage guard — see rooms/assignments.blade.php's
    // $guestTypeLabels for the same pattern).
    $sorts = [
        'last' => __('registration::admin.payments_sort_last'),
        'first' => __('registration::admin.payments_sort_first'),
        'group' => __('registration::admin.payments_sort_group'),
    ];
@endphp

@section('content')
    @include('registration::partials.admin-nav')


    <h1>{{ __('registration::admin.payments_title') }}</h1>
    <p class="text-muted">{{ __('registration::admin.payments_intro') }}</p>

    @if (session('payments_status'))
        <div class="alert alert-success">{{ session('payments_status') }}</div>
    @endif

    <div class="iccm-row iccm-row-loose">
        <span>{{ __('registration::admin.payments_sort_label') }}:</span>
        @foreach ($sorts as $value => $sortLabel)
            <a href="{{ route($routeName('admin.payments'), ['sort' => $value]) }}"
                class="btn btn-sm {{ $sort === $value ? 'btn-primary' : 'btn-outline-primary' }}">{{ $sortLabel }}</a>
        @endforeach
    </div>

    @if ($registrants->isEmpty())
        <p class="text-muted">{{ __('registration::admin.payments_no_registrants') }}</p>
    @endif

    @foreach ($registrants as $registrant)
        <div class="card mb-3">
            <div class="card-header payments-card-header">
                <div>
                    <strong>{{ $questions->fullName($registrant) }}</strong>
                    @if ($registrant->group?->is_group)
                        <span class="badge badge-info">{{ __('registration::admin.payments_group_badge') }}</span>
                        @if ($registrant->is_group_admin)
                            <span class="badge badge-secondary">{{ __('registration::admin.payments_leader_badge') }}</span>
                        @endif
                    @endif
                    <span class="text-muted">— {{ __('registration::admin.payments_cost_label') }}: {{ $money($registrant->cost()) }}</span>
                </div>
                <div>
                    <a href="{{ route($routeName('admin.payments.show'), $registrant->getKey()) }}" class="btn btn-sm btn-outline-secondary">{{ __('registration::admin.payments_view') }}</a>
                    <a href="{{ route($routeName('admin.payments.answers.edit'), $registrant->getKey()) }}" class="btn btn-sm btn-outline-secondary">{{ __('registration::admin.payments_edit_answers') }}</a>
                </div>
            </div>
            <div class="card-body">
                @php($breakdown = $registrant->paymentsBreakdown())
                @if (! empty($breakdown['lines']))
                    <table class="table table-sm payments-breakdown-table">
                        <caption class="sr-only">{{ __('registration::admin.payments_registrant_costs_heading') }}</caption>
                        <tbody>
                            @foreach ($breakdown['lines'] as $line)
                                <tr>
                                    <td>{{ $line['label'] }}</td>
                                    <td>{{ $money($line['amount']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif

                @if ($registrant->guests->isNotEmpty())
                    <div class="payments-guests-list">
                        @foreach ($registrant->guests as $guest)
                            <div class="payments-guest-row">
                                <span>
                                    {{ $guestQuestions->displayName($guest) }}
                                    <span class="text-muted">— {{ __('registration::admin.payments_cost_label') }}: {{ $money($breakdown['guestTotals'][$guest->getKey()]) }}</span>
                                </span>
                                <a href="{{ route($routeName('admin.payments.guests.answers.edit'), [$registrant->getKey(), $guest->getKey()]) }}" class="btn btn-sm btn-outline-secondary">{{ __('registration::admin.payments_edit_answers') }}</a>
                            </div>
                        @endforeach
                    </div>
                @endif

                @include('registration::admin.payments.payment-form', ['registrant' => $registrant])
            </div>
        </div>
    @endforeach
@endsection
