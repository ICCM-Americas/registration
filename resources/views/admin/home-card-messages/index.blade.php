@extends(config('registration.layout'))

@section('title')
{{ __('registration::admin.home_card_page_title') }}
@endsection

@php
    // Literal per-key strings (a concatenated lang key would not be seen by
    // the translation-coverage guard).
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


    <h1>{{ __('registration::admin.home_card_page_title') }}</h1>
    <p class="text-muted">{{ __('registration::admin.home_card_page_intro') }}</p>

    <div class="card mb-3">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center iccm-gap">
            <strong>{{ __('registration::admin.closed_messages') }}</strong>
        </div>

        {{-- Mirrors the Closed Page messages list: the set is fixed, so there
             is no reordering, hiding, adding or deleting. --}}
        <ul class="list-group list-group-flush">
            @foreach ($messages as $message)
                <li class="list-group-item js-badge-row d-flex flex-wrap justify-content-between align-items-center iccm-gap">
                    <span>
                        <strong>{{ $messageLabels[$message->key] }}</strong>
                        {{-- Kept in the DOM (d-none when off) so the translations modal can toggle it on close. --}}
                        <span class="badge badge-primary ml-1{{ $message->isTranslated() ? '' : ' d-none' }}" data-badge="translated">{{ __('registration::admin.badge_translated') }}</span>
                        <small class="d-block text-muted">{{ $messageDescriptions[$message->key] }}</small>
                    </span>
                    <span class="d-inline-flex flex-wrap align-items-center iccm-gap">
                        <a href="{{ route($routeName('admin.home_card_messages.edit'), $message->key) }}" class="btn btn-sm btn-outline-primary">{{ __('registration::admin.edit') }}</a>
                        <a href="{{ route($routeName('admin.translations'), ['home-card-message', $message->id]) }}" class="btn btn-sm btn-outline-secondary js-editor-link">{{ __('registration::admin.translations') }}</a>
                    </span>
                </li>
            @endforeach
        </ul>
    </div>

    @include('registration::partials.editor-modal')
@endsection
