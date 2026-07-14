@extends(config('registration.layout'))

@section('title')
{{ __('registration::info.title') }}
@endsection

@section('content')
		<div class="container">
			<div class="row justify-content-center">
				<div class="col-md-8">
					<div class="card">
						<div class="card-header">{{ __('registration::info.title') }}</div>

						<div class="card-body">
						@if (session('registered'))
						<div class="alert alert-success"><b>{{ __('registration::info.thanks_strong') }}</b>
						{{ __('registration::info.thanks_body') }}
						</div>
						@endif
						{{ __('registration::info.welcome', ['name' => $branding->siteName()]) }}
						<br />
						<br/>
						{{-- Admin-configured steps (see the admin "Steps" page), numbered
						     as rendered so hiding or removing one renumbers the rest. --}}
						@foreach ($steps as $step)
						<h3>{{ __('registration::info.step_heading', ['number' => $loop->iteration, 'title' => $vars($step->translate('heading'))]) }}</h3>
						{{ $vars($step->translate('body')) }}
						<br />
						<br />
						@endforeach
						<!-- <h3>{{ __('registration::info.login_or_register_heading') }}</h3> -->
						@guest
						{{-- Account creation and login belong to the host application. A
						     visitor logs in or creates an account first, then registers. --}}
						{{ __('registration::info.guest_prompt') }}<br />
						<br />
						@if (Route::has(config('registration.login_route')))
						<a href="{{ route(config('registration.login_route')) }}" class="btn center-block btn-primary mr-2">{{ __('registration::common.login') }}</a>
						@endif
						@if (Route::has(config('registration.register_route')))
						<a href="{{ route(config('registration.register_route')) }}" class="btn center-block btn-primary">{{ __('registration::common.register_account') }}</a>
						@endif
						@else
						@if (auth()->user()->group)
						{{ __('registration::info.logged_in_prompt') }}<br />
						<br />
						@else
						{{ __('registration::info.register_prompt') }}<br />
						<br />
						<a href="{{ route($routeName('register')) }}" class="btn center-block btn-primary">{{ __('registration::common.register_conference') }}</a><br />
						@endif
						@endguest
						</div>
					</div>
				</div>
			</div>
		</div>
@endsection
