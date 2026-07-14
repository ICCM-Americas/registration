{{--
    Assignment coverage warnings (admin dashboard): a red banner appears only
    while someone still lacks a room or a Prayer Pals assignment — nothing
    shows once everyone is placed, or while no one is eligible yet. Figures
    come from {@see \ConferenceTools\Registration\Services\DashboardSummary::roomAssignmentCoverage()}/{@see \ConferenceTools\Registration\Services\DashboardSummary::prayerPalsCoverage()}.

    Expects: $roomAssignmentCoverage, $prayerPalsCoverage (both
    ['assigned', 'total', 'complete']).
--}}
@if ($roomAssignmentCoverage['total'] > 0 && ! $roomAssignmentCoverage['complete'])
    <div class="alert alert-danger">
        {{ trans_choice(
            'registration::admin.room_assignments_missing',
            $roomAssignmentCoverage['total'] - $roomAssignmentCoverage['assigned'],
            ['count' => $roomAssignmentCoverage['total'] - $roomAssignmentCoverage['assigned']],
        ) }}
        <a href="{{ route(config('registration.route_name_prefix').'admin.rooms.assignments') }}">{{ __('registration::admin.assignments_title') }}</a>
    </div>
@endif

@if ($prayerPalsCoverage['total'] > 0 && ! $prayerPalsCoverage['complete'])
    <div class="alert alert-danger">
        {{ trans_choice(
            'registration::admin.prayer_pals_missing',
            $prayerPalsCoverage['total'] - $prayerPalsCoverage['assigned'],
            ['count' => $prayerPalsCoverage['total'] - $prayerPalsCoverage['assigned']],
        ) }}
        <a href="{{ route(config('registration.route_name_prefix').'admin.logistics.prayer_pals') }}">{{ __('registration::admin.prayer_pals_title') }}</a>
    </div>
@endif
