{{--
    The visibility editor: fetched over AJAX by the console and shown in a
    modal, for a question's rule or one option's rule. The root carries the
    state the console needs to refresh the originating row's tags when the
    modal closes: data-tags maps each [data-badge] name to whether it should
    show.

    Expects: $node (the conditionable model), $visPrefix (route-name prefix,
    "admin.questions", "admin.sections" or "admin.options"), $canHide (whether
    the node supports the "always hidden" flag), $noun (what the node is
    called in the fixed texts), $tags, $intro, $subjectLabel, $subjectKey,
    $rootGroups, $controllingQuestions, $controllingSubjects (built-in
    subjects, e.g. a guest's own type — usually empty), $booleanOperators,
    $conditionOperators.
--}}
{{-- .visibility-group-card is defined in partials/editor-modal.blade.php:
     this fragment is fetched over AJAX and injected into that modal, whose
     nonce (not this response's own) is what the page's CSP actually allows. --}}
<div class="js-editor"
     data-title="{{ __('registration::admin.visibility_title') }}"
     data-tags='@json($tags)'>

    <div class="js-editor-errors"></div>

    <p class="text-muted">
        {{ $intro }}
        <strong>{{ $subjectLabel }}</strong> <code>{{ $subjectKey }}</code>
    </p>

    @if ($canHide && $node->isHidden())
        <div class="alert alert-dark">{{ __('registration::admin.visibility_never', ['noun' => $noun]) }}</div>
        <form method="POST" action="{{ route($routeName($visPrefix.'.show'), $node) }}">
            @csrf
            <button type="submit" class="btn btn-primary">{{ __('registration::admin.visibility_make_visible') }}</button>
        </form>
    @elseif ($rootGroups->isEmpty())
        <div class="alert alert-secondary">{{ __('registration::admin.visibility_always', ['noun' => $noun]) }}</div>
        <div class="d-flex">
            <form method="POST" action="{{ route($routeName($visPrefix.'.rule.store'), $node) }}">
                @csrf
                <button type="submit" class="btn btn-primary">{{ __('registration::admin.visibility_start') }}</button>
            </form>
            @if ($canHide)
                <form method="POST" action="{{ route($routeName($visPrefix.'.hide'), $node) }}" class="ml-2">
                    @csrf
                    <button type="submit" class="btn btn-outline-danger">{{ __('registration::admin.visibility_never_set', ['noun' => $noun]) }}</button>
                </form>
            @endif
        </div>
    @else
        <p class="text-muted">{{ __('registration::admin.visibility_shown_when') }}</p>

        @foreach ($rootGroups as $group)
            @include('registration::admin.questions.group', ['group' => $group])
        @endforeach

        <div class="d-flex mt-3">
            <form method="POST" action="{{ route($routeName($visPrefix.'.rule.destroy'), $node) }}"
                  class="js-confirm-submit" data-confirm="{{ __('registration::admin.visibility_confirm_remove') }}">
                @csrf @method('DELETE')
                <button type="submit" class="btn btn-secondary btn-sm">{{ __('registration::admin.visibility_remove') }}</button>
            </form>
            @if ($canHide)
                <form method="POST" action="{{ route($routeName($visPrefix.'.hide'), $node) }}" class="ml-2 js-confirm-submit"
                      data-confirm="{{ __('registration::admin.visibility_confirm_hide', ['noun' => $noun]) }}">
                    @csrf
                    <button type="submit" class="btn btn-outline-danger btn-sm">{{ __('registration::admin.visibility_never_set', ['noun' => $noun]) }}</button>
                </form>
            @endif
        </div>
    @endif
</div>
