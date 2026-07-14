{{--
    The translations editor: fetched over AJAX by the consoles and shown in a
    modal. The root carries the state the console needs to refresh the row's
    tags when the modal closes: data-tags maps each [data-badge] name to
    whether it should show.

    Expects: $type, $id, $items, $locales, $locked (only ever true for a
    question — steps/sections/closed-messages are never locked).
--}}
{{-- .translations-locale-input is defined in partials/editor-modal.blade.php:
     this fragment is fetched over AJAX and injected into that modal, whose
     nonce (not this response's own) is what the page's CSP actually allows. --}}
<div class="js-editor"
     data-title="{{ __('registration::admin.translations_title') }}"
     data-tags='@json(['translated' => $items->contains->isTranslated()])'>

    <div class="js-editor-errors"></div>

    <p class="text-muted">{{ __('registration::admin.translations_intro') }}</p>

    {{-- One card per language the entity already has texts in. --}}
    @foreach ($locales as $locale)
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <strong>{{ $locale }}</strong>
                <form method="POST" action="{{ route($routeName('admin.translations.locale.destroy'), [$type, $id, $locale]) }}" class="js-confirm-submit" data-confirm="{{ __('registration::admin.confirm_delete_language') }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-sm btn-outline-danger" {{ $locked ? 'disabled' : '' }}>{{ __('registration::admin.delete') }}</button>
                </form>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route($routeName('admin.translations.save'), [$type, $id]) }}">
                    @csrf
                    <input type="hidden" name="locale" value="{{ $locale }}">
                    @include('registration::admin.translations.fields', ['items' => $items, 'locale' => $locale, 'locked' => $locked])
                    <button type="submit" class="btn btn-sm btn-primary" {{ $locked ? 'disabled' : '' }}>{{ __('registration::admin.save') }}</button>
                </form>
            </div>
        </div>
    @endforeach

    {{-- Add another language: same fields, empty, with a locale code input. --}}
    <div class="card mb-4">
        <div class="card-header">{{ __('registration::admin.add_language') }}</div>
        <div class="card-body">
            <form method="POST" action="{{ route($routeName('admin.translations.save'), [$type, $id]) }}">
                @csrf
                <div class="form-group">
                    <label for="new-locale">{{ __('registration::admin.locale') }}</label>
                    {{-- The hyphen is escaped for the browser's v-flag regex
                         compilation, where a bare "-" in a class is an error. --}}
                    <input type="text" id="new-locale" name="locale" maxlength="12" pattern="[A-Za-z]{2,3}([\-_][A-Za-z0-9]{2,8})?" class="form-control form-control-sm translations-locale-input" required {{ $locked ? 'disabled' : '' }}>
                    <small class="form-text text-muted">{{ __('registration::admin.locale_hint') }}</small>
                </div>
                @include('registration::admin.translations.fields', ['items' => $items, 'locale' => null, 'locked' => $locked])
                <button type="submit" class="btn btn-sm btn-secondary" {{ $locked ? 'disabled' : '' }}>{{ __('registration::admin.add') }}</button>
            </form>
        </div>
    </div>
</div>
