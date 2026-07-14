<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $branding->siteName() }} - @yield('title')</title>

    {{-- Neutral default chrome. Override config('registration.layout') to wrap
         these screens in the host application's own layout instead. --}}
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css" rel="stylesheet">

    {{-- The shared design system and layout utilities from
         conference-tools/branding, which this package's markup is written
         against, plus this package's own stylesheet. A host normally links the
         shared pair itself; this fallback layout links them so the package's
         screens render correctly in isolation too. --}}
    <link rel="stylesheet" href="{{ asset('vendor/branding/css/iccm.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/branding/css/iccm-utilities.css') }}">
    <link rel="stylesheet" href="{{ asset('vendor/registration/css/registration.css') }}">

    {{-- The host's branding colors (resolved from the BrandingProvider, falling
         back to the neutral DefaultBranding palette when the host binds
         nothing). Per-install database values, so they cannot live in a static
         stylesheet; the rules that consume them are in registration.css. --}}
    <style nonce="{{ $cspNonce ?? '' }}">
        :root {
            --color-primary: {{ $branding->color('primary') }};
            --color-secondary: {{ $branding->color('secondary') }};
            --color-bg: {{ $branding->color('background') }};
            --color-text: {{ $branding->color('text') }};
        }
    </style>

    @stack('head')
</head>

<body>
    <div id="app">
        <nav class="navbar navbar-expand-md navbar-light navbar-brand-bar shadow-sm">
            <div class="container">
                <a class="navbar-brand" href="{{ url('/') }}">
                    @if ($branding->logoUrl())
                        <img src="{{ $branding->logoUrl() }}" alt="{{ $branding->siteName() }}" height="30" class="d-inline-block align-top mr-2">
                    @endif
                    {{ $branding->siteName() }}
                </a>

                <div class="collapse navbar-collapse">
                    <ul class="navbar-nav mr-auto">
                        <li class="nav-item">
                            <a class="nav-link" href="{{ route($routeName('info')) }}"><b>{{ __('registration::common.registration') }}</b></a>
                        </li>
                    </ul>

                    <ul class="navbar-nav ml-auto">
                        @guest
                            @if (Route::has(config('registration.login_route')))
                                <li class="nav-item">
                                    <a class="nav-link" href="{{ route(config('registration.login_route')) }}">{{ __('registration::common.login') }}</a>
                                </li>
                            @endif
                        @endguest
                    </ul>
                </div>
            </div>
        </nav>

        <main class="py-4">
            @yield('content')
        </main>
    </div>
</body>

</html>
