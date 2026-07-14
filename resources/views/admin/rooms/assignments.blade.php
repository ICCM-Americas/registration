@extends(config('registration.layout'))

@section('title')
{{ __('registration::admin.assignments_title') }}
@endsection

@php
    // One shared room picker per form: "wing / floor / room (n free)".
    $roomOptions = $rooms->map(fn ($room) => [
        'id' => $room->id,
        'label' => $room->fullName().' ('.trans_choice('registration::admin.assignments_vacancies', $room->vacancies(), ['count' => $room->vacancies()]).')',
    ]);

    // Literal per-key calls (a concatenated key would not be seen by the
    // translation-coverage guard — see pricing/index.blade.php's
    // $perDiemModeLabels for the same pattern).
    $guestTypeLabels = [
        'adult' => __('registration::common.guest_type_adult'),
        'minor' => __('registration::common.guest_type_minor'),
    ];

    $zones = $rooms->groupBy(fn ($room) => $room->wing.'|'.$room->floor);

    $occupiedRoomsCount = $rooms->filter(fn ($room) => $room->assignments->isNotEmpty())->count();
    $emptyRoomsCount = $rooms->count() - $occupiedRoomsCount;
@endphp

@section('content')
    @include('registration::partials.report-toolbar')


    <h1>{{ __('registration::admin.assignments_title') }}</h1>
    <p class="text-muted no-print">{{ __('registration::admin.assignments_intro') }}</p>

    @if (session('assignments_status'))
        <div class="alert alert-success no-print">{{ session('assignments_status') }}</div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger no-print">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($rooms->isEmpty())
        <p class="text-muted">{{ __('registration::admin.assignments_no_rooms') }}</p>
    @else
        <div class="no-print iccm-row iccm-row-loose">
            <form method="POST" action="{{ route($routeName('admin.rooms.assignments.first_pass')) }}" class="mb-0">
                @csrf
                <button type="submit" class="btn btn-primary">{{ __('registration::admin.assignments_first_pass') }}</button>
            </form>
            <form method="POST" action="{{ route($routeName('admin.rooms.assignments.unassign_all')) }}" class="js-confirm-submit mb-0" data-confirm="{{ __('registration::admin.confirm_remove_all_assignments') }}">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn btn-sm btn-danger">{{ __('registration::admin.assignments_remove_all') }}</button>
            </form>
        </div>

        <div class="card mb-3 no-print">
            <div class="card-header"><strong>{{ __('registration::admin.assignments_occupancy_title') }}</strong></div>
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <span>{{ __('registration::admin.assignments_occupied_label') }}</span>
                    <strong>{{ $occupiedRoomsCount }}</strong>
                </div>
                <div class="d-flex justify-content-between align-items-center">
                    <span>{{ __('registration::admin.assignments_empty_label') }}</span>
                    <strong>{{ $emptyRoomsCount }}</strong>
                </div>
            </div>
        </div>

        <div class="card mb-3 no-print">
            <div class="card-header"><strong>{{ __('registration::admin.assignments_unassigned') }}</strong></div>
            <div class="card-body">
                @if ($unassigned->isEmpty())
                    <p class="text-muted mb-0">{{ __('registration::admin.assignments_all_assigned') }}</p>
                @endif
                @foreach ($unassigned as $occupant)
                    @php($occupantInfo = $info[$occupant->getMorphClass().':'.$occupant->getKey()])
                    <div class="iccm-row">
                        <span class="iccm-field-14">
                            {{ $occupantInfo['name'] }}
                            @if ($occupantInfo['type'] === 'guest')
                                <span class="badge {{ $occupant->type->value === 'minor' ? 'badge-warning' : 'badge-info' }}">{{ $guestTypeLabels[$occupant->type->value] }}</span>
                            @endif
                        </span>
                        <span class="text-muted iccm-field-fill">
                            {{ collect([
                                $occupantInfo['gender'],
                                $occupantInfo['roommate'] ? __('registration::admin.assignments_roommate').': '.$occupantInfo['roommate'] : null,
                                $occupantInfo['host'] ? __('registration::admin.assignments_attendee').': '.$occupantInfo['host'] : null,
                            ])->filter()->implode(' · ') }}
                        </span>
                        <form method="POST" action="{{ route($routeName('admin.rooms.assignments.assign')) }}" class="iccm-row iccm-row-tight">
                            @csrf
                            <input type="hidden" name="occupant_type" value="{{ $occupantInfo['type'] }}">
                            <input type="hidden" name="occupant_id" value="{{ $occupant->getKey() }}">
                            <select name="room_id" class="form-control form-control-sm w-auto" required>
                                <option value="">{{ __('registration::admin.assignments_pick_room') }}</option>
                                @foreach ($roomOptions as $option)
                                    <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                                @endforeach
                            </select>
                            <button type="submit" class="btn btn-sm btn-secondary">{{ __('registration::admin.assignments_assign') }}</button>
                        </form>
                    </div>
                @endforeach
            </div>
        </div>

        @foreach ($zones as $zoneRooms)
            @php($zone = $zoneRooms->first())
            <div class="card mb-3 iccm-avoid-break">
                <div class="card-header">
                    <strong>{{ __('registration::admin.rooms_zone', ['wing' => $zone->wing, 'floor' => $zone->floor]) }}</strong>
                    — {{ $zone->designation->label() }}
                </div>
                <div class="card-body">
                    @foreach ($zoneRooms as $room)
                        <div class="js-room assignments-room-row" data-capacity="{{ $room->capacity }}">
                            <strong>{{ $room->name }}</strong>
                            <span class="badge badge-danger bg-danger js-over-capacity-badge" {{ $room->assignments->count() > $room->capacity ? '' : 'hidden' }}>{{ __('registration::admin.assignments_over_capacity') }}</span>
                            @foreach ($room->assignments as $assignment)
                                @php($assignmentType = $assignment->assignable_type === \ConferenceTools\Registration\Models\Guest::class ? 'guest' : 'user')
                                @php($occupant = $occupantsByKey->get($assignment->assignable_type.':'.$assignment->assignable_id))
                                @php($occupantInfo = $occupant ? $info[$assignment->assignable_type.':'.$assignment->assignable_id] : null)
                                <div class="js-assignment-row iccm-row iccm-row-indent">
                                    <span class="iccm-field-14">
                                        {{ $occupant ? $guestQuestions->occupantFullName($occupant, $questions) : '#'.$assignment->assignable_id }}
                                        @if ($assignmentType === 'guest')
                                            <span class="badge {{ $occupant && $occupant->type->value === 'minor' ? 'badge-warning' : 'badge-info' }}">{{ $occupant ? $guestTypeLabels[$occupant->type->value] : '' }}</span>
                                        @endif
                                    </span>
                                    @if ($occupantInfo && ($occupantInfo['roommate'] || $occupantInfo['host']))
                                        <span class="text-muted iccm-field-fill">
                                            {{ collect([
                                                $occupantInfo['roommate'] ? __('registration::admin.assignments_roommate').': '.$occupantInfo['roommate'] : null,
                                                $occupantInfo['host'] ? __('registration::admin.assignments_attendee').': '.$occupantInfo['host'] : null,
                                            ])->filter()->implode(' · ') }}
                                        </span>
                                    @endif
                                    <form method="POST" action="{{ route($routeName('admin.rooms.assignments.assign')) }}" class="no-print iccm-row iccm-row-tight">
                                        @csrf
                                        <input type="hidden" name="occupant_type" value="{{ $assignmentType }}">
                                        <input type="hidden" name="occupant_id" value="{{ $assignment->assignable_id }}">
                                        <select name="room_id" class="form-control form-control-sm w-auto" required>
                                            <option value="">{{ __('registration::admin.assignments_pick_room') }}</option>
                                            @foreach ($roomOptions as $option)
                                                @if ($option['id'] !== $room->id)
                                                    <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                                                @endif
                                            @endforeach
                                        </select>
                                        <button type="submit" class="btn btn-sm btn-secondary">{{ __('registration::admin.assignments_move') }}</button>
                                    </form>
                                    <button type="button" class="btn btn-sm btn-outline-danger no-print js-unassign" data-url="{{ route($routeName('admin.rooms.assignments.unassign'), $assignment) }}">{{ __('registration::admin.assignments_remove') }}</button>
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    @endif

    <script nonce="{{ $cspNonce ?? '' }}">
    (function () {
        var csrf = document.querySelector('meta[name="csrf-token"]').content;

        document.querySelectorAll('.js-confirm-submit').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                if (!confirm(form.dataset.confirm)) {
                    e.preventDefault();
                }
            });
        });

        document.querySelectorAll('.js-unassign').forEach(function (button) {
            button.addEventListener('click', function () {
                fetch(button.dataset.url, {
                    method: 'DELETE',
                    headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                }).then(function (response) {
                    if (!response.ok) {
                        alert(@json(__('registration::admin.editor_failed')));
                        return;
                    }

                    var room = button.closest('.js-room');
                    button.closest('.js-assignment-row').remove();

                    var badge = room.querySelector('.js-over-capacity-badge');
                    badge.hidden = !(room.querySelectorAll('.js-assignment-row').length > Number(room.dataset.capacity));
                }).catch(function () {
                    alert(@json(__('registration::admin.editor_failed')));
                });
            });
        });
    })();
    </script>
@endsection
