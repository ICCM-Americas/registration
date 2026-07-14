
<div class="card mb-3">
    <div class="card-body">
        <h2 class="mt-0">{{ __('registration::admin.registrations') }}</h2>
        <div class="reg-stat-row">
            <div>
                <div class="iccm-stat-value">{{ $registrationsComplete }}</div>
                <div class="text-muted">{{ __('registration::admin.registrations_complete') }}</div>
            </div>
            <div>
                <div class="iccm-stat-value">{{ $registrationsIncomplete }}</div>
                <div class="text-muted">{{ __('registration::admin.registrations_incomplete') }}</div>
            </div>
            @isset($attendeesCount)
                <div>
                    <div class="iccm-stat-value">{{ $attendeesCount + $guestsCount }}</div>
                    <div class="text-muted">{{ __('registration::admin.registrations_total_people') }}</div>
                </div>
                <div>
                    <div class="iccm-stat-value">{{ $attendeesCount }}</div>
                    <div class="text-muted">{{ __('registration::admin.registrations_attendees') }}</div>
                </div>
                <div>
                    <div class="iccm-stat-value">{{ $guestsCount }}</div>
                    <div class="text-muted">{{ __('registration::admin.registrations_guests') }}</div>
                </div>
                <div>
                    <div class="iccm-stat-value">
                        <a href="{{ route(config('registration.route_name_prefix').'admin.reports') }}">{{ $specialNeedsCount }}</a>
                    </div>
                    <div class="text-muted">{{ __('registration::admin.registrations_special_needs') }}</div>
                </div>
                <div>
                    <div class="iccm-stat-value">
                        <a href="{{ route(config('registration.route_name_prefix').'admin.logistics.shuttles') }}">{{ $shuttleRunsCount }}</a>
                    </div>
                    <div class="text-muted">{{ __('registration::admin.registrations_shuttle_runs') }}</div>
                </div>
            @endisset
        </div>
        @if ($withLink ?? false)
            <p class="mt-3 mb-0">
                <a href="{{ route(config('registration.route_name_prefix').'admin.dashboard') }}" class="btn btn-primary">{{ __('registration::admin.title') }}</a>
            </p>
        @endif
    </div>
</div>
