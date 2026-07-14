{{-- Conference edition controls (admin dashboard): the name and year every
     admin-authored text references via {conference_name}/{conference_year},
     the "start next conference" rollover that archives the outgoing edition
     so the closed registration page keeps naming it, and the .ZIP of CSVs
     exports of the current/archived registration data. --}}
<x-registration::admin-card :title="__('registration::admin.conference_title')" status-key="conference_status" class="mt-3">
    <p class="text-muted">{{ __('registration::admin.conference_intro') }}</p>

    <form method="POST" action="{{ route($routeName('admin.conference.update')) }}">
        @csrf
        @method('PUT')
        <div class="form-group">
            <label for="conference_name">{{ __('registration::admin.conference_name') }}</label>
            <input type="text" id="conference_name" name="conference_name" class="form-control form-control-sm"
                   maxlength="255" value="{{ old('conference_name', $conferenceEdition->name()) }}">
        </div>
        <div class="form-group">
            <label for="conference_year">{{ __('registration::admin.conference_year') }}</label>
            <input type="text" inputmode="numeric" id="conference_year" name="conference_year" class="form-control form-control-sm"
                   maxlength="4" value="{{ old('conference_year', $conferenceEdition->year()) }}">
        </div>
        <button type="submit" class="btn btn-primary btn-sm">{{ __('registration::admin.conference_save') }}</button>
    </form>

    <div class="mt-3">
        <form method="POST" action="{{ route($routeName('admin.conference.next')) }}"
              class="js-confirm-submit" data-confirm="{{ __('registration::admin.conference_next_confirm') }}">
            @csrf
            <button type="submit" class="btn btn-secondary btn-sm">{{ __('registration::admin.conference_next') }}</button>
        </form>
        <small class="d-block text-muted mt-1">{{ __('registration::admin.conference_next_hint') }}</small>
    </div>

    <div class="mt-3">
        <a class="btn btn-outline-secondary btn-sm" href="{{ route($routeName('admin.conference.registrations.csv')) }}">
            {{ __('registration::admin.conference_export_registrations') }}
        </a>
        <small class="d-block text-muted mt-1">{{ __('registration::admin.conference_export_registrations_hint') }}</small>
    </div>

    @if ($archiveExists)
        <div class="mt-3">
            <a class="btn btn-outline-secondary btn-sm" href="{{ route($routeName('admin.conference.archive.csv')) }}">
                {{ __('registration::admin.conference_export_archive') }}
            </a>
            <small class="d-block text-muted mt-1">{{ __('registration::admin.conference_export_archive_hint') }}</small>
        </div>
    @endif

    {{-- After a rollover, the closed registration page keeps naming the
         outgoing conference; make that visible so the divergence from the
         fields above is never a surprise. --}}
    @if ($conferenceEdition->closedName() !== $conferenceEdition->name() || $conferenceEdition->closedYear() !== $conferenceEdition->year())
        <p class="text-muted mt-2 mb-0">{{ __('registration::admin.conference_closed_shows', ['name' => trim($conferenceEdition->closedName().' '.$conferenceEdition->closedYear())]) }}</p>
    @endif
</x-registration::admin-card>
