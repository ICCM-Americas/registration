@extends(config('registration.layout'))

@section('title')
{{ __('registration::admin.questions_title') }}
@endsection

@section('content')
    @include('registration::partials.admin-nav')


    <h1>{{ __('registration::admin.questions_title') }}</h1>
    <p class="text-muted">{{ __('registration::admin.questions_intro') }}</p>

    @if (session('questions_error'))
        <div class="alert alert-danger">{{ session('questions_error') }}</div>
    @endif

    <div id="builder"
        data-reorder-url="{{ route($routeName('admin.questions.reorder')) }}"
        data-csrf="{{ csrf_token() }}"
        data-locked="{{ $locked ? '1' : '0' }}">

        <div id="builder-status" class="mb-2 iccm-status-line"></div>

        @php($scopeLabels = ['participant' => __('registration::admin.scope_participant'), 'group' => __('registration::admin.scope_group'), 'guest' => __('registration::admin.scope_guest'), 'group_member' => __('registration::admin.scope_group_member')])

        @foreach ($scopes as $scopeValue => $sections)
            <h2 class="h4 mt-4">{{ $scopeLabels[$scopeValue] ?? $scopeValue }}</h2>

            <form method="POST" action="{{ route($routeName('admin.sections.store')) }}" class="form-inline mb-2">
                @csrf
                <input type="hidden" name="scope" value="{{ $scopeValue }}">
                <input type="text" name="title" class="form-control mr-2" placeholder="{{ __('registration::admin.section_title') }}" required>
                <button type="submit" class="btn btn-sm btn-secondary">{{ __('registration::admin.add_section') }}</button>
            </form>

            <div class="sections" data-scope="{{ $scopeValue }}">
                @foreach ($sections as $section)
                    {{-- The id anchors deep links to a section — the test drive's
                         exit button lands here — without any scripted scrolling. --}}
                    <div id="section-{{ $section->id }}" class="card mb-3 section js-badge-row" data-section-id="{{ $section->id }}">
                        {{-- flex-wrap + gap (not margins): on a narrow screen the title
                             and the button group become their own physical rows inside
                             the one logical row, without adding separators. --}}
                        <div class="card-header section-handle d-flex flex-wrap justify-content-between align-items-center iccm-gap iccm-drag">
                            <span>
                                <span class="text-muted mr-2">⠿</span>
                                <strong>{{ $section->title }}</strong>
                                @if ($section->is_system)<span class="badge badge-warning ml-1">{{ __('registration::admin.badge_system') }}</span>@endif
                                {{-- Kept in the DOM (d-none when off) so the editor modals can toggle them on close. --}}
                                <span class="badge badge-dark ml-1{{ $section->isHidden() ? '' : ' d-none' }}" data-badge="hidden">{{ __('registration::admin.badge_hidden') }}</span>
                                <span class="badge badge-light border ml-1{{ $section->conditionGroups->isNotEmpty() ? '' : ' d-none' }}" data-badge="conditional">{{ __('registration::admin.badge_conditional') }}</span>
                                <span class="badge badge-primary ml-1{{ $section->isTranslated() ? '' : ' d-none' }}" data-badge="translated">{{ __('registration::admin.badge_translated') }}</span>
                            </span>
                            <span class="d-inline-flex flex-wrap align-items-center iccm-gap">
                                {{-- Anchors ignore the disabled attribute, so a locked "Add
                                     question" drops its href and gets aria-disabled instead. --}}
                                <a href="{{ $locked ? '#' : route($routeName('admin.questions.create'), ['section' => $section->id]) }}"
                                   class="btn btn-sm btn-primary{{ $locked ? ' disabled js-disabled-link' : '' }}"
                                   @if ($locked) aria-disabled="true" tabindex="-1" @endif>{{ __('registration::admin.add_question') }}</a>
                                <a href="{{ route($routeName('admin.sections.visibility'), $section) }}" class="btn btn-sm btn-outline-secondary js-editor-link">{{ __('registration::admin.visibility') }}</a>
                                <a href="{{ route($routeName('admin.translations'), ['section', $section->id]) }}" class="btn btn-sm btn-outline-secondary js-editor-link">{{ __('registration::admin.translations') }}</a>
                                @unless ($section->is_system)
                                    <form method="POST" action="{{ route($routeName('admin.sections.destroy'), $section) }}" class="js-confirm-submit" data-confirm="{{ __('registration::admin.confirm_delete_section') }}">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-danger">{{ __('registration::admin.delete') }}</button>
                                    </form>
                                @endunless
                            </span>
                        </div>

                        <ul class="list-group list-group-flush question-list" data-section-id="{{ $section->id }}">
                            @forelse ($section->questions as $question)
                                {{-- The id anchors the Save/Cancel redirect from the question
                                     form back to roughly the same place, without scripted
                                     scrolling. --}}
                                <li id="question-{{ $question->id }}" class="list-group-item question js-badge-row d-flex flex-wrap justify-content-between align-items-center iccm-gap {{ $locked ? '' : 'iccm-drag' }}" data-question-id="{{ $question->id }}">
                                    <span>
                                        <span class="text-muted mr-2{{ $locked ? ' iccm-drag-locked' : '' }}">⠿</span>
                                        <strong>{{ $question->label }}</strong>
                                        <code class="ml-1">{{ $question->key }}</code>
                                        <span class="badge badge-info ml-1">{{ $question->type->value }}</span>
                                        @if ($question->is_system)<span class="badge badge-warning">{{ __('registration::admin.badge_system') }}</span>@endif
                                        @if ($question->required)<span class="badge badge-secondary">{{ __('registration::admin.badge_required') }}</span>@endif
                                        @if ($question->isPriced())<span class="badge badge-success">{{ __('registration::admin.badge_priced') }}</span>@endif
                                        {{-- Kept in the DOM (d-none when off) so the editor modals can toggle them on close. --}}
                                        <span class="badge badge-dark{{ $question->isHidden() ? '' : ' d-none' }}" data-badge="hidden">{{ __('registration::admin.badge_hidden') }}</span>
                                        <span class="badge badge-light border{{ $question->conditionGroups->isNotEmpty() ? '' : ' d-none' }}" data-badge="conditional">{{ __('registration::admin.badge_conditional') }}</span>
                                        <span class="badge badge-primary{{ $question->isTranslated() ? '' : ' d-none' }}" data-badge="translated">{{ __('registration::admin.badge_translated') }}</span>
                                    </span>
                                    <span class="d-inline-flex flex-wrap align-items-center iccm-gap">
                                        {{-- Always a live link — while locked it opens the same
                                             form read-only, so it's relabeled "View" rather than disabled. --}}
                                        <a href="{{ route($routeName('admin.questions.edit'), $question) }}" class="btn btn-sm btn-outline-primary">{{ $locked ? __('registration::admin.view') : __('registration::admin.edit') }}</a>
                                        <a href="{{ route($routeName('admin.questions.visibility'), $question) }}" class="btn btn-sm btn-outline-secondary js-editor-link">{{ __('registration::admin.visibility') }}</a>
                                        <a href="{{ route($routeName('admin.translations'), ['question', $question->id]) }}" class="btn btn-sm btn-outline-secondary js-editor-link">{{ __('registration::admin.translations') }}</a>
                                        @unless ($question->is_system)
                                            <form method="POST" action="{{ route($routeName('admin.questions.destroy'), $question) }}" class="js-confirm-submit" data-confirm="{{ __('registration::admin.confirm_delete_question') }}">
                                                @csrf @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-danger" {{ $locked ? 'disabled' : '' }}>{{ __('registration::admin.delete') }}</button>
                                            </form>
                                        @endunless
                                    </span>
                                </li>
                            @empty
                                <li class="list-group-item text-muted">{{ __('registration::admin.no_questions') }}</li>
                            @endforelse
                        </ul>
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>

    @include('registration::partials.editor-modal')

    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.6/Sortable.min.js"></script>
    <script nonce="{{ $cspNonce ?? '' }}">
    (function () {
        document.querySelectorAll('.js-confirm-submit').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                if (!confirm(form.dataset.confirm)) {
                    e.preventDefault();
                }
            });
        });

        document.querySelectorAll('.js-disabled-link').forEach(function (link) {
            link.addEventListener('click', function (e) {
                e.preventDefault();
            });
        });

        var builder = document.getElementById('builder');
        if (!builder || typeof Sortable === 'undefined') return;

        var url = builder.dataset.reorderUrl;
        var csrf = builder.dataset.csrf;
        var locked = builder.dataset.locked === '1';
        var statusEl = document.getElementById('builder-status');

        function serialize() {
            var sections = [];
            builder.querySelectorAll('.sections').forEach(function (cont) {
                cont.querySelectorAll('.section').forEach(function (sec, i) {
                    sections.push({ id: parseInt(sec.dataset.sectionId, 10), position: i });
                });
            });
            var questions = [];
            builder.querySelectorAll('.question-list').forEach(function (list) {
                var sectionId = parseInt(list.dataset.sectionId, 10);
                list.querySelectorAll('.question').forEach(function (item, i) {
                    questions.push({ id: parseInt(item.dataset.questionId, 10), section_id: sectionId, position: i });
                });
            });
            return { sections: sections, questions: questions };
        }

        function status(key, ok) {
            statusEl.innerHTML = '<span class="badge badge-' + (ok ? 'success' : 'danger') + '">' + key + '</span>';
        }

        function save() {
            fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                body: JSON.stringify(serialize())
            }).then(function (r) {
                status(r.ok ? @json(__('registration::admin.order_saved')) : @json(__('registration::admin.order_save_failed')), r.ok);
            }).catch(function () {
                status(@json(__('registration::admin.order_save_failed')), false);
            });
        }

        // Reorder questions within and between sections of the same scope —
        // skipped while locked (questions are not draggable, but sections
        // still are, below).
        builder.querySelectorAll('.sections').forEach(function (cont) {
            var scope = cont.dataset.scope;

            if (!locked) {
                cont.querySelectorAll('.question-list').forEach(function (list) {
                    new Sortable(list, {
                        group: 'questions-' + scope,
                        draggable: '.question',
                        animation: 150,
                        onEnd: save
                    });
                });
            }

            // Reorder the sections (steps) themselves.
            new Sortable(cont, {
                draggable: '.section',
                handle: '.section-handle',
                animation: 150,
                onEnd: save
            });
        });
    })();
    </script>
@endsection
