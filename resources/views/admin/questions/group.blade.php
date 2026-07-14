{{--
    One node of a visibility rule tree (a question's or a single option's),
    rendered recursively. Shows the group's AND/OR operator, its leaf
    conditions, its nested subgroups, and the forms to add/remove each. Every
    action is a plain form submit handled on the server (no client logic).

    Expects: $node (the conditionable model), $visPrefix (route-name prefix),
    $group, $controllingQuestions, $controllingSubjects (built-in subjects a
    condition may test instead of a question's answer — usually empty),
    $booleanOperators, $conditionOperators, $locked (inherited from the
    visibility editor's scope through every recursive @include of this
    partial — Blade @include merges into, rather than replaces, the parent
    view's data).
--}}
<div class="card mb-2 border-left-primary visibility-group-card">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <form method="POST" action="{{ route($routeName($visPrefix.'.groups.update'), [$node, $group]) }}" class="form-inline">
                @csrf @method('PATCH')
                <span class="mr-2">{{ __('registration::admin.visibility_match') }}</span>
                {{-- requestSubmit(), not submit(): it fires the submit event, so
                     the editor modal's AJAX interception sees the change. --}}
                <select name="operator" class="form-control form-control-sm mr-2 js-auto-submit" {{ $locked ? 'disabled' : '' }}>
                    @foreach ($booleanOperators as $operator)
                        <option value="{{ $operator->value }}" {{ $group->operator === $operator ? 'selected' : '' }}>
                            {{ strtoupper($operator->value) }}
                        </option>
                    @endforeach
                </select>
                <span>{{ __('registration::admin.visibility_of_following') }}</span>
                <noscript><button type="submit" class="btn btn-sm btn-link">{{ __('registration::admin.save') }}</button></noscript>
            </form>

            <form method="POST" action="{{ route($routeName($visPrefix.'.groups.destroy'), [$node, $group]) }}"
                  class="js-confirm-submit" data-confirm="{{ __('registration::admin.visibility_confirm_group') }}">
                @csrf @method('DELETE')
                <button type="submit" class="btn btn-outline-danger btn-sm" {{ $locked ? 'disabled' : '' }}>&times;</button>
            </form>
        </div>

        <ul class="list-group list-group-flush mb-2">
            @forelse ($group->conditions as $condition)
                <li class="list-group-item d-flex justify-content-between align-items-center px-2 py-1">
                    <span>
                        <code>{{ $condition->question?->key ?? $condition->subject?->label() }}</code>
                        <span class="badge badge-secondary">{{ $condition->operator->value }}</span>
                        @if ($condition->operator->needsValue())<strong>{{ $condition->value }}</strong>@endif
                    </span>
                    <form method="POST" action="{{ route($routeName($visPrefix.'.conditions.destroy'), [$node, $condition]) }}">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-outline-danger btn-sm py-0" {{ $locked ? 'disabled' : '' }}>&times;</button>
                    </form>
                </li>
            @empty
                <li class="list-group-item text-muted px-2 py-1">{{ __('registration::admin.visibility_no_conditions') }}</li>
            @endforelse
        </ul>

        {{-- Add a condition to this group. --}}
        <form method="POST" action="{{ route($routeName($visPrefix.'.conditions.store'), $node) }}" class="form-inline mb-2">
            @csrf
            <input type="hidden" name="condition_group_id" value="{{ $group->id }}">
            <select name="question_id" class="form-control form-control-sm mr-1" {{ $locked ? 'disabled' : '' }}>
                <option value="">{{ __('registration::admin.visibility_pick_question') }}</option>
                @foreach ($controllingQuestions as $candidate)
                    <option value="{{ $candidate->id }}">{{ $candidate->key }}</option>
                @endforeach
            </select>
            @if (! empty($controllingSubjects))
                <select name="subject" class="form-control form-control-sm mr-1" {{ $locked ? 'disabled' : '' }}>
                    <option value="">{{ __('registration::admin.visibility_pick_subject') }}</option>
                    @foreach ($controllingSubjects as $subject)
                        <option value="{{ $subject->value }}">{{ $subject->label() }}</option>
                    @endforeach
                </select>
            @endif
            <select name="operator" class="form-control form-control-sm mr-1" {{ $locked ? 'disabled' : '' }}>
                @foreach ($conditionOperators as $operator)
                    <option value="{{ $operator->value }}">{{ $operator->value }}</option>
                @endforeach
            </select>
            <input type="text" name="value" class="form-control form-control-sm mr-1" placeholder="{{ __('registration::admin.visibility_value') }}" {{ $locked ? 'disabled' : '' }}>
            <button type="submit" class="btn btn-sm btn-secondary" {{ $locked ? 'disabled' : '' }}>{{ __('registration::admin.visibility_add_condition') }}</button>
        </form>

        {{-- Nested subgroups. --}}
        @foreach ($group->children as $child)
            @include('registration::admin.questions.group', ['group' => $child])
        @endforeach

        <form method="POST" action="{{ route($routeName($visPrefix.'.groups.store'), $node) }}">
            @csrf
            <input type="hidden" name="parent_group_id" value="{{ $group->id }}">
            <input type="hidden" name="operator" value="{{ \ConferenceTools\Registration\Enums\BooleanOperator::And->value }}">
            <button type="submit" class="btn btn-sm btn-outline-secondary" {{ $locked ? 'disabled' : '' }}>{{ __('registration::admin.visibility_add_group') }}</button>
        </form>
    </div>
</div>
