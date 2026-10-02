@extends(config('registration.layout'))

@section('title')
{{ __('registration::admin.search_title') }}
@endsection

@php
    // Literal per-key calls (a concatenated key would not be seen by the
    // translation-coverage guard).
    $categoryLabels = [
        'questions' => __('registration::admin.search_category_questions'),
        'reports' => __('registration::admin.search_category_reports'),
        'answers' => __('registration::admin.search_category_answers'),
    ];
    $placeLabels = [
        'question_text' => __('registration::admin.search_place_question_text'),
        'options' => __('registration::admin.search_place_options'),
        'question_rules' => __('registration::admin.search_place_question_rules'),
        'report_text' => __('registration::admin.search_place_report_text'),
        'report_columns' => __('registration::admin.search_place_report_columns'),
        'report_rules' => __('registration::admin.search_place_report_rules'),
        'answers' => __('registration::admin.search_place_answers'),
        'drafts' => __('registration::admin.search_place_drafts'),
    ];
@endphp

@section('content')
    @include('registration::partials.admin-nav')

    <h1>{{ __('registration::admin.search_title') }}</h1>
    <p class="text-muted">{{ __('registration::admin.search_intro') }}</p>

    <form method="GET" action="{{ route($routeName('admin.search')) }}" class="card mb-3">
        <div class="card-body">
            <div class="form-group">
                <label for="search-q">{{ __('registration::admin.search_term') }}</label>
                <div class="reg-search-bar">
                    <input type="text" id="search-q" name="q" value="{{ $options->term }}" maxlength="500" required
                           class="form-control @error('q') is-invalid @enderror">
                    <button type="submit" class="btn btn-primary">{{ __('registration::admin.search_submit') }}</button>
                </div>
                @error('q')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                <div class="reg-search-choices mt-2">
                    <label class="iccm-checkbox-row">
                        <input type="checkbox" id="search-regex" name="regex" value="1" @checked($options->regex)>
                        {{ __('registration::admin.search_regex') }}
                    </label>
                    <label class="iccm-checkbox-row">
                        <input type="checkbox" id="search-case" name="case" value="1" @checked($options->caseSensitive)>
                        {{ __('registration::admin.search_case') }}
                    </label>
                </div>
            </div>

            <fieldset class="mb-0">
                <legend class="h6">{{ __('registration::admin.search_in') }}</legend>
                @foreach ($categories as $category => $places)
                    <div class="reg-search-category js-search-category">
                        <label class="iccm-checkbox-row">
                            <input type="checkbox" id="search-category-{{ $category }}" class="js-category-toggle">
                            <strong>{{ $categoryLabels[$category] }}</strong>
                        </label>
                        <div class="reg-search-choices">
                            @foreach ($places as $place)
                                <label class="iccm-checkbox-row">
                                    <input type="checkbox" id="search-in-{{ $place }}" name="in[]" value="{{ $place }}" class="js-place" @checked($options->has($place))>
                                    {{ $placeLabels[$place] }}
                                </label>
                            @endforeach
                            @if ($category === 'questions')
                                <label class="iccm-checkbox-row">
                                    <input type="checkbox" id="search-translations" name="translations" value="1" @checked($options->translations)>
                                    {{ __('registration::admin.search_translations') }}
                                </label>
                            @endif
                        </div>
                    </div>
                @endforeach
                @error('in')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            </fieldset>
        </div>
    </form>

    @foreach ($results ?? [] as $category => $page)
        <div class="card mb-3 js-search-results" data-category="{{ $category }}">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center iccm-gap">
                <h2 class="h5 mb-0">{{ __('registration::admin.search_results_heading', ['category' => $categoryLabels[$category], 'count' => $page->total()]) }}</h2>
                @if ($category === 'answers' && $page->total() > 0)
                    <span class="d-inline-flex flex-wrap align-items-center iccm-gap">
                        <label class="iccm-checkbox-row reg-search-choice">
                            <input type="checkbox" id="search-select-page" class="js-select-page">
                            {{ __('registration::admin.search_select_page') }}
                        </label>
                        <a href="#" class="d-none js-select-all" data-total="{{ $page->total() }}"
                           data-select="{{ __('registration::admin.search_select_all', ['count' => $page->total()]) }}"
                           data-selected="{{ __('registration::admin.search_selected_all', ['count' => $page->total()]) }}">{{ __('registration::admin.search_select_all', ['count' => $page->total()]) }}</a>
                        <a href="{{ route($routeName('admin.search.registrations.preview')) }}" class="btn btn-sm btn-danger disabled js-delete-selected" aria-disabled="true"
                           data-preview="{{ route($routeName('admin.search.registrations.preview')) }}"
                           data-all-query="{{ http_build_query($options->toQuery() + ['all' => 1]) }}">{{ __('registration::admin.search_delete_selected') }}</a>
                    </span>
                @endif
            </div>
            @if ($page->isEmpty())
                <div class="card-body text-muted">{{ __('registration::admin.search_no_matches') }}</div>
            @else
                <ul class="list-group list-group-flush">
                    @foreach ($page as $hit)
                        @include('registration::admin.search.hit', ['hit' => $hit, 'returnTo' => $returnTo])
                    @endforeach
                </ul>
                @if ($page->hasPages())
                    <div class="card-body py-2">{{ $page->links('pagination::bootstrap-4') }}</div>
                @endif
            @endif
        </div>
    @endforeach

    @include('registration::partials.editor-modal')

    <script nonce="{{ $cspNonce ?? '' }}">
    (function () {
        // A category's own box toggles the places beside it, and shows
        // whether all, some, or none of them are checked.
        document.querySelectorAll('.js-search-category').forEach(function (row) {
            var toggle = row.querySelector('.js-category-toggle');
            var places = Array.prototype.slice.call(row.querySelectorAll('.js-place'));
            function sync() {
                var checked = places.filter(function (p) { return p.checked; }).length;
                toggle.checked = checked === places.length;
                toggle.indeterminate = checked > 0 && checked < places.length;
            }
            toggle.addEventListener('change', function () {
                places.forEach(function (p) { p.checked = toggle.checked; });
            });
            places.forEach(function (p) { p.addEventListener('change', sync); });
            sync();
        });

        var results = document.querySelector('.js-search-results[data-category="answers"]');
        var pageBox = results && results.querySelector('.js-select-page');
        if (!pageBox) return;

        var targets = Array.prototype.slice.call(results.querySelectorAll('.js-search-target'));
        var allLink = results.querySelector('.js-select-all');
        var del = results.querySelector('.js-delete-selected');
        var all = false; // every match across all pages, not just the checked boxes

        // The Delete button opens the shared editor modal only while it's a
        // .js-editor-link, so it carries that class only when something is selected.
        function update() {
            var picked = targets.filter(function (t) { return t.checked; });
            var full = picked.length === targets.length;
            all = all && full;
            pageBox.checked = full;
            pageBox.indeterminate = picked.length > 0 && !full;
            allLink.classList.toggle('d-none', !full || parseInt(allLink.dataset.total, 10) <= targets.length);
            allLink.textContent = all ? allLink.dataset.selected : allLink.dataset.select;
            del.href = del.dataset.preview + '?' + (all
                ? del.dataset.allQuery
                : picked.map(function (t) { return 'targets[]=' + encodeURIComponent(t.value); }).join('&'));
            var on = picked.length > 0;
            del.classList.toggle('disabled', !on);
            del.classList.toggle('js-editor-link', on);
            del.setAttribute('aria-disabled', on ? 'false' : 'true');
        }

        pageBox.addEventListener('change', function () {
            targets.forEach(function (t) { t.checked = pageBox.checked; });
            update();
        });
        targets.forEach(function (t) { t.addEventListener('change', update); });
        allLink.addEventListener('click', function (e) {
            e.preventDefault();
            all = true;
            update();
        });
        del.addEventListener('click', function (e) {
            if (!del.classList.contains('js-editor-link')) e.preventDefault();
        });
        update();
    })();
    </script>
@endsection
