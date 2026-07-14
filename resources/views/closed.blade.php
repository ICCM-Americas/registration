@extends(config('registration.layout'))

@section('title')
{{ __('registration::info.closed_title') }}
@endsection

@section('content')
		<div class="container">
			<div class="row justify-content-center">
				<div class="col-md-8">
					<div class="card">
						<div class="card-header">{{ __('registration::info.closed_title') }}</div>

						<div class="card-body">
						{{-- The admin-configured message for the current window state
						     (see the admin "Closed Page" console), translated per locale
						     and with its {token} variables resolved. The text is escaped
						     first; bare URLs (e.g. from {conference_site_url}) then
						     become links so "visit the conference site" is clickable. --}}
						{!! preg_replace(
							'~(https?://[^\s<]+[^\s<.,;:!?)\]])~',
							'<a href="$1" target="_blank" rel="noopener">$1</a>',
							e($vars($closedMessage->translate('body'))),
						) !!}
						</div>
					</div>
				</div>
			</div>
		</div>
@endsection
