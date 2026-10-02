
<nav class="iccm-toolbar">
    <a href="{{ route($routeName('admin.dashboard')) }}" class="btn btn-primary">{{ __('registration::admin.nav_dashboard') }}</a>
    <a href="{{ route($routeName('admin.closed')) }}" class="btn btn-primary">{{ __('registration::admin.nav_closed_page') }}</a>
    <a href="{{ route($routeName('admin.emails')) }}" class="btn btn-primary">{{ __('registration::admin.nav_emails') }}</a>
    <a href="{{ route($routeName('admin.home_card_messages')) }}" class="btn btn-primary">{{ __('registration::admin.nav_home_card_messages') }}</a>
    <a href="{{ route($routeName('admin.steps')) }}" class="btn btn-primary">{{ __('registration::admin.nav_landing_page') }}</a>
    <a href="{{ route($routeName('admin.rooms')) }}" class="btn btn-primary">{{ __('registration::admin.nav_lodging') }}</a>
    <a href="{{ route($routeName('admin.logistics')) }}" class="btn btn-primary">{{ __('registration::admin.nav_logistics') }}</a>
    <a href="{{ route($routeName('admin.payments')) }}" class="btn btn-primary">{{ __('registration::admin.nav_payments') }}</a>
    <a href="{{ route($routeName('admin.pricing')) }}" class="btn btn-primary">{{ __('registration::admin.nav_pricing') }}</a>
    <a href="{{ route($routeName('admin.questions')) }}" class="btn btn-primary">{{ __('registration::admin.nav_questions') }}</a>
    <a href="{{ route($routeName('admin.reports')) }}" class="btn btn-primary">{{ __('registration::admin.nav_reports') }}</a>
    <a href="{{ route($routeName('admin.search')) }}" class="btn btn-primary">{{ __('registration::admin.nav_search') }}</a>
    <a href="{{ route($routeName('admin.variables')) }}" class="btn btn-primary">{{ __('registration::admin.nav_variables') }}</a>
    {{-- Secondary on purpose: an action (test drive the wizard), not a console page. --}}
    <a href="{{ route($routeName('admin.test')) }}" class="btn btn-secondary">{{ __('registration::admin.nav_test_registration') }}</a>
</nav>

{{-- Completing a test run returns to the dashboard; the flash confirms it here
     so it shows wherever the admin lands. --}}
@if (session('test_status'))
    <div class="alert alert-success">{{ session('test_status') }}</div>
@endif

{{-- The registration window has arrived but the email guard is holding
     registration closed: warn on every admin page (this partial is included by
     all of them) with the same message the refused "open now" action flashes. --}}
@if ($registrationStatus->misconfigured())
    <div class="alert alert-danger">{{ __('registration::admin.window_email_required') }}</div>
@endif

{{-- The window has arrived but a badge-name, first-name, or last-name
     question hasn't been nominated: warn the same way, linking to where it's
     fixed. --}}
@if ($registrationStatus->requiredNameQuestionsMissing())
    <div class="alert alert-danger">
        {{ __('registration::admin.window_name_questions_required') }}
        <a href="{{ route($routeName('admin.logistics')) }}">{{ __('registration::admin.nav_logistics') }}</a>
    </div>
@endif

{{-- A Logistics matching-answer setting no longer matches an option on its
     nominated question: warn on every admin page regardless of the window,
     since this is a configuration-integrity problem, not a scheduling one. --}}
@if ($registrationStatus->reportAnswersStale())
    <div class="alert alert-danger">
        {{ __('registration::admin.window_report_answers_stale') }}
        <a href="{{ route($routeName('admin.logistics')) }}">{{ __('registration::admin.nav_logistics') }}</a>
    </div>
@endif
