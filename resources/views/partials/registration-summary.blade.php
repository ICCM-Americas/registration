{{--
    A registrant's (and their guests') answers plus their itemized cost
    summary — shared by the admin Payments "show" page and the registrant-
    facing "My Registration" page. $editAnswersUrl and $editGuestAnswersUrl
    (a callable(Guest): ?string) are optional; omit them (or leave null) to
    render a read-only view with no edit links.

    Expects: $registrant, $fields (the registrant's own answer fields),
    $guestQuestions, $costSummary, $def.
--}}
@php
    $editAnswersUrl ??= null;
    $editGuestAnswersUrl ??= null;
@endphp


<div class="card mb-3">
    <div class="card-header reg-summary-card-header">
        <strong>{{ __('registration::admin.payments_answers_heading') }}</strong>
        @if ($editAnswersUrl)
            <a href="{{ $editAnswersUrl }}" class="btn btn-sm btn-outline-secondary">{{ __('registration::admin.payments_edit_answers') }}</a>
        @endif
    </div>
    <div class="card-body">
        @if (empty($fields))
            <p class="text-muted mb-0">{{ __('registration::admin.payments_no_answers') }}</p>
        @else
            <table class="table table-sm mb-0">
                <tbody>
                    @foreach ($fields as $field)
                        <tr>
                            <th class="reg-summary-label-col">{!! $label($field['label']) !!}</th>
                            <td>{{ $field['value'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>

@foreach ($registrant->guests as $guest)
    @php($guestFields = $guest->registrationAnswers()->fields())
    <div class="card mb-3">
        <div class="card-header reg-summary-card-header">
            <strong>{{ __('registration::admin.payments_guest_answers_heading', ['name' => $guestQuestions->displayName($guest)]) }}</strong>
            @if ($editGuestAnswersUrl && $editGuestAnswersUrl($guest))
                <a href="{{ $editGuestAnswersUrl($guest) }}" class="btn btn-sm btn-outline-secondary">{{ __('registration::admin.payments_edit_answers') }}</a>
            @endif
        </div>
        <div class="card-body">
            @if (empty($guestFields))
                <p class="text-muted mb-0">{{ __('registration::admin.payments_no_answers') }}</p>
            @else
                <table class="table table-sm mb-0">
                    <tbody>
                        @foreach ($guestFields as $field)
                            <tr>
                                <th class="reg-summary-label-col">{!! $label($field['label']) !!}</th>
                                <td>{{ $field['value'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
@endforeach

@include('registration::partials.cost-summary', ['costSummary' => $costSummary, 'def' => $def])
