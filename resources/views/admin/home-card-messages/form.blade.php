@extends(config('registration.layout'))

@section('title')
{{ __('registration::admin.home_card_page_title') }}
@endsection

@php
    $messageLabels = [
        'before_open' => __('registration::admin.home_card_label_before_open'),
        'after_close' => __('registration::admin.home_card_label_after_close'),
    ];
    $messageDescriptions = [
        'before_open' => __('registration::admin.home_card_desc_before_open'),
        'after_close' => __('registration::admin.home_card_desc_after_close'),
    ];
@endphp

@section('content')
    @include('registration::partials.admin-nav')

    <h1>{{ $messageLabels[$homeCardMessage->key] }}</h1>
    <p class="text-muted">{{ $messageDescriptions[$homeCardMessage->key] }}</p>
    <p class="text-muted">{{ __('registration::admin.closed_tokens_hint') }}</p>

    <form method="POST" action="{{ route($routeName('admin.home_card_messages.update'), $homeCardMessage->key) }}">
        @csrf
        @method('PUT')

        <div class="form-group">
            <label for="body">{{ __('registration::admin.field_body') }}</label>
            <textarea name="body" id="body" rows="5" class="form-control @error('body') is-invalid @enderror" required>{{ old('body', $homeCardMessage->body) }}</textarea>
            @error('body')<span class="invalid-feedback">{{ $message }}</span>@enderror
        </div>

        <button type="submit" class="btn btn-primary">{{ __('registration::admin.save') }}</button>
        <a href="{{ route($routeName('admin.home_card_messages')) }}" class="btn btn-danger">{{ __('registration::admin.cancel') }}</a>
        {{-- The shared modal (same one the console list uses) opens over AJAX
             from this link's href; home-card-message rows always exist. --}}
        <a href="{{ route($routeName('admin.translations'), ['home-card-message', $homeCardMessage->id]) }}" class="btn btn-outline-secondary js-editor-link">{{ __('registration::admin.translations') }}</a>
    </form>

    @include('registration::partials.editor-modal')
@endsection
