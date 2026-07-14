{{-- Registration window controls (admin dashboard): current state, the
     scheduled open/close dates, and the manual open/close-now actions. --}}
<x-registration::admin-card :title="__('registration::admin.window_title')" status-key="window_status">
    @if (session('window_error'))
        <div class="alert alert-danger">{{ session('window_error') }}</div>
    @endif
    {{-- Null-safe: the dashboard is also rendered outside HTTP (tests render
         the view directly), where the session error bag is never shared. --}}
    @if (($errors ?? null)?->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <p>
        @if ($registrationStatus->isOpen())
            <span class="badge badge-success bg-success">{{ __('registration::admin.window_status_open') }}</span>
        @else
            <span class="badge badge-secondary bg-secondary">{{ __('registration::admin.window_status_closed') }}</span>
        @endif
    </p>

    <form method="POST" action="{{ route($routeName('admin.window.update')) }}">
        @csrf
        @method('PUT')
        <div class="form-group">
            <label for="opens_at">{{ __('registration::admin.window_opens_at') }}</label>
            <input type="datetime-local" id="opens_at" name="opens_at" class="form-control form-control-sm"
                   value="{{ old('opens_at', $registrationStatus->opensAt()?->format('Y-m-d\TH:i')) }}">
        </div>
        <div class="form-group">
            <label for="closes_at">{{ __('registration::admin.window_closes_at') }}</label>
            <input type="datetime-local" id="closes_at" name="closes_at" class="form-control form-control-sm"
                   value="{{ old('closes_at', $registrationStatus->closesAt()?->format('Y-m-d\TH:i')) }}">
        </div>
        <button type="submit" class="btn btn-primary btn-sm">{{ __('registration::admin.window_save') }}</button>
    </form>

    <div class="mt-3">
        <form method="POST" action="{{ route($routeName('admin.window.open')) }}" class="d-inline">
            @csrf
            <button type="submit" class="btn btn-success btn-sm">{{ __('registration::admin.window_open_now') }}</button>
        </form>
        <form method="POST" action="{{ route($routeName('admin.window.close')) }}" class="d-inline">
            @csrf
            <button type="submit" class="btn btn-outline-danger btn-sm">{{ __('registration::admin.window_close_now') }}</button>
        </form>
    </div>
</x-registration::admin-card>
