@extends(config('registration.layout'))

@section('title')
{{ __('registration::admin.closed_page_title') }}
@endsection

@php
    // Literal per-key strings (a concatenated lang key would not be seen by
    // the translation-coverage guard).
    $messageLabels = [
        'before_open' => __('registration::admin.closed_label_before_open'),
        'opening_soon' => __('registration::admin.closed_label_opening_soon'),
        'after_close' => __('registration::admin.closed_label_after_close'),
        'closed' => __('registration::admin.closed_label_closed'),
    ];
    $messageDescriptions = [
        'before_open' => __('registration::admin.closed_desc_before_open'),
        'opening_soon' => __('registration::admin.closed_desc_opening_soon'),
        'after_close' => __('registration::admin.closed_desc_after_close'),
        'closed' => __('registration::admin.closed_desc_closed'),
    ];
@endphp

@section('content')
    @include('registration::partials.admin-nav')

    <h1>{{ $messageLabels[$closedMessage->key] }}</h1>
    <p class="text-muted">{{ $messageDescriptions[$closedMessage->key] }}</p>
    <p class="text-muted">{{ __('registration::admin.closed_tokens_hint') }}</p>

    <form method="POST" action="{{ route($routeName('admin.closed.update'), $closedMessage->key) }}">
        @csrf
        @method('PUT')

        <div class="form-group">
            <label for="body">{{ __('registration::admin.field_body') }}</label>
            <textarea name="body" id="body" rows="5" class="form-control @error('body') is-invalid @enderror" required>{{ old('body', $closedMessage->body) }}</textarea>
            @error('body')<span class="invalid-feedback">{{ $message }}</span>@enderror
        </div>

        <button type="submit" class="btn btn-primary">{{ __('registration::admin.save') }}</button>
        <a href="{{ route($routeName('admin.closed')) }}" class="btn btn-danger">{{ __('registration::admin.cancel') }}</a>
        {{-- The shared modal (same one the console list uses) opens over AJAX
             from this link's href; closed-message rows always exist. --}}
        <a href="{{ route($routeName('admin.translations'), ['closed-message', $closedMessage->id]) }}" class="btn btn-outline-secondary js-editor-link">{{ __('registration::admin.translations') }}</a>
    </form>

    @include('registration::partials.editor-modal')
@endsection
