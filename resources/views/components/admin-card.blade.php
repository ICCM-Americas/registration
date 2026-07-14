{{-- Admin dashboard card: heading, the flash status message keyed to the
     card, then the caller's body. --}}
@props(['title', 'statusKey'])
<div {{ $attributes->merge(['class' => 'card']) }}>
    <div class="card-body">
        <h2 class="mt-0">{{ $title }}</h2>
        @if (session($statusKey))
            <div class="alert alert-success">{{ session($statusKey) }}</div>
        @endif
        {{ $slot }}
    </div>
</div>
