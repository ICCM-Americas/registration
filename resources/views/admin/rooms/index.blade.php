@extends(config('registration.layout'))

@section('title')
{{ __('registration::admin.rooms_title') }}
@endsection

@section('content')
    @include('registration::partials.admin-nav')


    <h1>{{ __('registration::admin.rooms_title') }}</h1>
    <p class="text-muted">{{ __('registration::admin.rooms_intro') }}</p>

    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($zones->isEmpty())
        <p class="text-muted">{{ __('registration::admin.rooms_none') }}</p>
    @endif

    @foreach ($zones as $rooms)
        @php $zone = $rooms->first(); @endphp
        <div class="card mb-3">
            <div class="card-header iccm-row iccm-row-tight">
                <strong>{{ __('registration::admin.rooms_zone', ['wing' => $zone->wing, 'floor' => $zone->floor]) }}</strong>

                <form method="POST" action="{{ route($routeName('admin.rooms.zone')) }}" class="iccm-row iccm-row-tight">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="wing" value="{{ $zone->wing }}">
                    <input type="hidden" name="floor" value="{{ $zone->floor }}">
                    <select name="designation" class="form-control form-control-sm w-auto">
                        @foreach ($designations as $designation)
                            <option value="{{ $designation->value }}" @selected($zone->designation === $designation)>{{ $designation->label() }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="btn btn-sm btn-primary">{{ __('registration::admin.save') }}</button>
                </form>

                <form method="POST" action="{{ route($routeName('admin.rooms.zone.destroy')) }}" class="js-confirm-submit rooms-zone-delete" data-confirm="{{ __('registration::admin.confirm_delete_zone') }}">
                    @csrf
                    @method('DELETE')
                    <input type="hidden" name="wing" value="{{ $zone->wing }}">
                    <input type="hidden" name="floor" value="{{ $zone->floor }}">
                    <button type="submit" class="btn btn-sm btn-outline-danger">{{ __('registration::admin.delete_zone') }}</button>
                </form>
            </div>
            <div class="card-body">
                @foreach ($rooms as $room)
                    <div class="iccm-row">
                        <form method="POST" action="{{ route($routeName('admin.rooms.update'), $room) }}" class="iccm-row iccm-row-tight">
                            @csrf
                            @method('PUT')
                            <input type="text" name="name" value="{{ $room->name }}" class="form-control form-control-sm iccm-field-8" required>
                            <label class="mb-0">
                                {{ __('registration::admin.rooms_capacity') }}
                                <input type="number" name="capacity" value="{{ $room->capacity }}" min="1" max="20" class="form-control form-control-sm iccm-field-5 rooms-field-capacity" required>
                            </label>
                            <button type="submit" class="btn btn-sm btn-primary">{{ __('registration::admin.save') }}</button>
                        </form>
                        <span class="text-muted">{{ __('registration::admin.rooms_assigned_count', ['count' => $room->assignments_count]) }}</span>
                        <form method="POST" action="{{ route($routeName('admin.rooms.destroy'), $room) }}" class="js-confirm-submit" data-confirm="{{ __('registration::admin.confirm_delete_room') }}">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-outline-danger">{{ __('registration::admin.delete') }}</button>
                        </form>
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach

    <div class="card mb-4">
        <div class="card-header">{{ __('registration::admin.rooms_add') }}</div>
        <div class="card-body">
            <form method="POST" action="{{ route($routeName('admin.rooms.store')) }}" class="iccm-row">
                @csrf
                <input type="text" name="wing" placeholder="{{ __('registration::admin.rooms_wing') }}" class="form-control form-control-sm iccm-field-8" required>
                <input type="text" name="floor" placeholder="{{ __('registration::admin.rooms_floor') }}" class="form-control form-control-sm iccm-field-8" required>
                <input type="text" name="names" placeholder="{{ __('registration::admin.rooms_names') }}" class="form-control form-control-sm iccm-field-fill" required>
                <select name="designation" class="form-control form-control-sm w-auto">
                    @foreach ($designations as $designation)
                        <option value="{{ $designation->value }}">{{ $designation->label() }}</option>
                    @endforeach
                </select>
                <label class="mb-0">
                    {{ __('registration::admin.rooms_capacity') }}
                    <input type="number" name="capacity" value="2" min="1" max="20" class="form-control form-control-sm iccm-field-5 rooms-field-capacity" required>
                </label>
                <button type="submit" class="btn btn-sm btn-secondary">{{ __('registration::admin.add') }}</button>
            </form>
            <small class="form-text text-muted">{{ __('registration::admin.rooms_names_hint') }}</small>
        </div>
    </div>

    <script nonce="{{ $cspNonce ?? '' }}">
    document.querySelectorAll('.js-confirm-submit').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (!confirm(form.dataset.confirm)) {
                e.preventDefault();
            }
        });
    });
    </script>
@endsection
