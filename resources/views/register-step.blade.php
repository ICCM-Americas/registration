@extends(config('registration.layout'))

@section('title')
{{ __('Registration') }}
@endsection

@section('content')

<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-8">
            @include('registration::partials.test-mode-banner')

            @if ($step === null)
                <div class="alert alert-warning">{{ __('Registration is not configured yet.') }}</div>
            @else
                @php($stepQuestionIds = $step->questions->pluck('id'))

                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span>{{ __($step->section->translate('title')) }}</span>
                        <small class="text-muted">{{ __('Step') }} {{ $stepNumber }} / {{ $totalSteps }}</small>
                    </div>

                    <div class="card-body">
                        @if ($totalSteps > 0)
                            <div class="progress mb-3 register-step-progress">
                                <div class="progress-bar js-step-progress-bar" role="progressbar" data-progress-pct="{{ (int) round($stepNumber / max($totalSteps, 1) * 100) }}"></div>
                            </div>
                        @endif

                        @if ($groupMembersLinkVisible ?? false)
                            <a href="{{ $groupMembersUrl ?? route($routeName('register.group_members')) }}" class="btn btn-outline-secondary btn-sm mb-3">
                                {{ __('registration::common.manage_group_members_link') }}
                            </a>
                        @endif

                        @if ($guestsLinkVisible ?? false)
                            <a href="{{ $guestsUrl ?? route($routeName('register.guests')) }}" class="btn btn-outline-secondary btn-sm mb-3">
                                {{ __('registration::common.manage_guests_link') }}
                            </a>
                        @endif

                        <form id="registration-form" method="POST" action="{{ $formAction ?? route($routeName('register.store')) }}">
                            @csrf
                            <input type="hidden" name="_step" value="{{ $step->id() }}">

                            @if ($step->section->description)
                                <p class="text-muted">{{ __($step->section->translate('description')) }}</p>
                            @endif

                            @foreach ($step->questions as $question)
                                {{-- A rule is safe to toggle in the browser only when every
                                     question it depends on is on this same step; anything
                                     cross-step is decided by the server alone. --}}
                                @php($crossStep = array_diff($question->controllingQuestionIds(), $stepQuestionIds->all()))
                                @include('registration::questions.question', [
                                    'question' => $question,
                                    'answers' => $answers,
                                    'evaluator' => $evaluator,
                                    'def' => $def,
                                    'clientToggle' => empty($crossStep),
                                ])
                            @endforeach

                            {{-- The final step recaps the costs before the
                                 registrant commits — a plain summary, or the
                                 leader/member split for an invited member
                                 (see partials/cost-summary and
                                 partials/group-member-cost-summary). --}}
                            @if ($isLast)
                                @include('registration::partials.cost-summary')
                                @include('registration::partials.group-member-cost-summary')
                            @endif

                            <div class="d-flex justify-content-between mt-3">
                                <button type="submit" name="_direction" value="back" formnovalidate class="btn btn-outline-secondary" {{ $isFirst ? 'disabled' : '' }}>
                                    {{ __('Back') }}
                                </button>
                                <button type="submit" name="_direction" value="next" class="btn btn-primary">
                                    {{ $isLast ? __('Register') : __('Next') }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>

@include('registration::partials.visibility-toggle')
@include('registration::partials.yesno-toggle')

<script nonce="{{ $cspNonce ?? '' }}">
document.querySelectorAll('.js-step-progress-bar').forEach(function (bar) {
    bar.style.width = bar.dataset.progressPct + '%';
});
</script>
@endsection
