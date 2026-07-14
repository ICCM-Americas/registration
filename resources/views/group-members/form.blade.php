@extends(config('registration.layout'))

@section('title')
{{ __('registration::common.group_member_add') }}
@endsection

@section('content')
<div class="container">
    <div class="row justify-content-center">
        <div class="col-md-8">
            @include('registration::partials.test-mode-banner')

            <div class="card">
                <div class="card-header">
                    {{ __('registration::common.group_member_add') }}
                </div>

                <div class="card-body">
                    <form id="registration-form" method="POST" action="{{ $action }}">
                        @csrf

                        @foreach ($sections as $section)
                            @include('registration::questions.section', compact('section', 'answers', 'evaluator', 'def'))
                        @endforeach

                        <div class="d-flex justify-content-between mt-3">
                            <a href="{{ route($routeName($routePrefix)) }}" class="btn btn-outline-secondary">
                                {{ __('Back') }}
                            </a>
                            <button type="submit" class="btn btn-primary">
                                {{ __('registration::common.group_member_save') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

@include('registration::partials.visibility-toggle')
@include('registration::partials.yesno-toggle')
@endsection
