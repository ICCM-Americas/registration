@extends(config('registration.layout'))

@section('title')
{{ __('registration::admin.landing_title') }}
@endsection

@section('content')
    @include('registration::partials.admin-nav')

    <h1>{{ $step->exists ? __('registration::admin.edit') : __('registration::admin.add_step') }}</h1>

    <form method="POST" action="{{ $step->exists ? route($routeName('admin.steps.update'), $step) : route($routeName('admin.steps.store')) }}">
        @csrf
        @if ($step->exists) @method('PUT') @endif

        <div class="form-group">
            <label for="heading">{{ __('registration::admin.step_heading_label') }}</label>
            <input type="text" name="heading" id="heading" class="form-control @error('heading') is-invalid @enderror" value="{{ old('heading', $step->heading) }}" required>
            @error('heading')<span class="invalid-feedback">{{ $message }}</span>@enderror
        </div>

        <div class="form-group">
            <label for="body">{{ __('registration::admin.step_body_label') }}</label>
            <textarea name="body" id="body" rows="5" class="form-control @error('body') is-invalid @enderror" required>{{ old('body', $step->body) }}</textarea>
            @error('body')<span class="invalid-feedback">{{ $message }}</span>@enderror
        </div>

        <button type="submit" class="btn btn-primary">{{ __('registration::admin.save') }}</button>
        <a href="{{ route($routeName('admin.steps')) }}" class="btn btn-danger">{{ __('registration::admin.cancel') }}</a>
        {{-- Editing translations needs a saved row; the shared modal (same one
             the console list uses) opens over AJAX from this link's href. --}}
        @if ($step->exists)
            <a href="{{ route($routeName('admin.translations'), ['step', $step->id]) }}" class="btn btn-outline-secondary js-editor-link">{{ __('registration::admin.translations') }}</a>
        @endif
    </form>

    @if ($step->exists)
        @include('registration::partials.editor-modal')
    @endif
@endsection
