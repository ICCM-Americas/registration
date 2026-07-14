@extends(config('registration.layout'))

@section('title')
{{ __('registration::admin.emails_title') }}
@endsection

@php
    // Literal per-key names (a concatenated lang key would not be seen by the
    // translation-coverage guard).
    $templateNames = [
        'admin_notification' => __('registration::admin.email_admin_notification'),
        'registrant_confirmation' => __('registration::admin.email_registrant_confirmation'),
        'group_invite' => __('registration::admin.email_group_invite'),
    ];
@endphp

@section('content')
    @include('registration::partials.admin-nav')

    <h1>{{ __('registration::admin.emails_title') }}</h1>
    <p class="text-muted">{{ __('registration::admin.emails_intro') }}</p>
    <p class="text-muted">{{ __('registration::admin.emails_variables_hint') }}</p>

    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card mb-4">
        <div class="card-header">{{ __('registration::admin.email_addresses_title') }}</div>
        <div class="card-body">
            @if (session('addresses_status'))
                <div class="alert alert-success">{{ session('addresses_status') }}</div>
            @endif
            <p class="text-muted">{{ __('registration::admin.email_addresses_intro') }}</p>
            {{-- Old input only refills this card when its own form failed
                 validation (mirroring the template cards below). --}}
            @php($failed = old('_key') === 'addresses')
            <form method="POST" action="{{ route($routeName('admin.emails.addresses')) }}">
                @csrf
                @method('PUT')
                <input type="hidden" name="_key" value="addresses">
                <div class="form-group">
                    <label for="admin_email">{{ __('registration::admin.email_admin_address') }}</label>
                    <input type="email" id="admin_email" name="admin_email" value="{{ $failed ? old('admin_email') : $emails->adminEmail() }}" maxlength="255" class="form-control form-control-sm @error('admin_email') is-invalid @enderror">
                    <small class="form-text text-muted">{{ __('registration::admin.email_admin_address_hint') }}</small>
                </div>
                <div class="form-group">
                    <label for="from_email">{{ __('registration::admin.email_from_address') }}</label>
                    <input type="email" id="from_email" name="from_email" value="{{ $failed ? old('from_email') : $emails->fromEmail() }}" maxlength="255" class="form-control form-control-sm @error('from_email') is-invalid @enderror" placeholder="{{ config('mail.from.address') }}">
                    <small class="form-text text-muted">{{ __('registration::admin.email_from_address_hint') }}</small>
                </div>
                <button type="submit" class="btn btn-sm btn-primary">{{ __('registration::admin.save') }}</button>
            </form>
        </div>
    </div>

    @foreach ($templates as $template)
        {{-- Old input only refills the card whose form failed validation. --}}
        @php($failed = old('_key') === $template->key)
        <div class="card mb-4">
            <div class="card-header">{{ $templateNames[$template->key] }}</div>
            <div class="card-body">
                <form method="POST" action="{{ route($routeName('admin.emails.update'), $template->key) }}">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="_key" value="{{ $template->key }}">
                    <div class="form-group">
                        <label for="subject-{{ $template->key }}">{{ __('registration::admin.email_subject') }}</label>
                        <input type="text" id="subject-{{ $template->key }}" name="subject" value="{{ $failed ? old('subject') : $template->subject }}" maxlength="255" class="form-control form-control-sm" required>
                    </div>
                    <div class="form-group">
                        <label for="body-{{ $template->key }}">{{ __('registration::admin.email_body') }}</label>
                        <textarea id="body-{{ $template->key }}" name="body" rows="8" class="form-control form-control-sm" required>{{ $failed ? old('body') : $template->body }}</textarea>
                    </div>
                    <button type="submit" class="btn btn-sm btn-primary">{{ __('registration::admin.save') }}</button>
                </form>
            </div>
        </div>
    @endforeach
@endsection
