@extends(config('registration.layout'))

@section('title')
{{ $name }}
@endsection

@section('content')
    <h1>{{ $name }}</h1>

    @if (session('mine_status'))
        <div class="alert alert-success">{{ session('mine_status') }}</div>
    @endif

    @if ($groupMembers !== null)
        <div class="card mb-3">
            <div class="card-header"><strong>{{ __('registration::mine.group_members_heading') }}</strong></div>
            <div class="card-body">
                @if (empty($groupMembers))
                    <p class="text-muted mb-0">{{ __('registration::mine.group_members_none') }}</p>
                @else
                    <ul class="list-group list-group-flush">
                        @foreach ($groupMembers as $member)
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                {{ $member['name'] }}
                                @if ($member['registered'])
                                    <span class="badge badge-success">{{ __('registration::mine.group_member_registered') }}</span>
                                @else
                                    <span class="badge badge-secondary">{{ __('registration::mine.group_member_not_registered') }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    @endif

    @include('registration::partials.registration-summary', [
        'registrant' => $registrant,
        'fields' => $fields,
        'guestQuestions' => $guestQuestions,
        'costSummary' => $costSummary,
        'def' => $def,
    ])

    <div class="card mb-3">
        <div class="card-header"><strong>{{ __('registration::mine.paid_label') }}</strong></div>
        <div class="card-body">
            <p class="mb-0">{{ $registrant->payment?->is_paid ? __('registration::mine.paid_yes') : __('registration::mine.paid_no') }}</p>
        </div>
    </div>

    @if ($canModify)
        <a href="{{ route($routeName('mine.edit')) }}" class="btn btn-primary">{{ __('registration::mine.modify') }}</a>
        <a href="{{ route($routeName('mine.guests')) }}" class="btn btn-outline-primary">{{ __('registration::common.manage_guests_link') }}</a>
        @if ($registrant->is_group_admin)
            <a href="{{ route($routeName('mine.group_members')) }}" class="btn btn-outline-primary">{{ __('registration::common.manage_group_members_link') }}</a>
        @endif
    @elseif ($adminEmail)
        <p class="text-muted">{{ __('registration::mine.contact_admin_intro') }}</p>
        <a href="mailto:{{ $adminEmail }}" class="btn btn-outline-secondary">{{ __('registration::mine.email_administrator') }}</a>
    @endif
@endsection
