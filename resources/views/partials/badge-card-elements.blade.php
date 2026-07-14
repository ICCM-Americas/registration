{{--
    The custom badge layout: each $element is absolutely positioned by its own
    x_pct/y_pct/width_pct (percentage of the card's own size), so it renders
    identically whether the card is a 4x3in badge or a 2x3.5in business card —
    no per-size scale math needed, unlike the built-in default layout above.
    The PDF export mirrors this rendering in the badges view's client-side
    generator (see admin/logistics/badges.blade.php).

    Each element's position/size/font is written as data-* attributes because
    it is per-element: the values differ on every card, so they cannot be a
    rule in registration.css the way .badge-card-element's own positioning is.
    The one shared script in badges.blade.php reads them back and applies them.
--}}
@foreach ($elements as $element)
    <div class="badge-card-element"
        data-left-pct="{{ $element->x_pct }}"
        data-top-pct="{{ $element->y_pct }}"
        data-width-pct="{{ $element->width_pct }}"
        data-align="{{ $element->align }}"
        @if ($element->type->isTextual())
        data-font-size-pt="{{ $element->font_size_pt }}"
        data-bold="{{ $element->bold ? '1' : '0' }}"
        data-italic="{{ $element->italic ? '1' : '0' }}"
        @endif
    >
        @switch($element->type->value)
            @case('badge_name')
                {{ $badge['name'] }}
                @break
            @case('logo')
                @if ($branding->logoUrl())
                    <img src="{{ $branding->logoUrl() }}" alt="" class="badge-card-element__img">
                @endif
                @break
            @case('conference_name')
                {{ trim(($conferenceEdition->name() ?? $branding->siteName()).' '.$conferenceEdition->year()) }}
                @break
            @case('organization')
                {{ $badge['organization'] }}
                @break
            @case('static_text')
                {{ $element->text }}
                @break
            @case('static_image')
                @if ($element->image_data)
                    <img src="data:{{ $element->image_mime }};base64,{{ $element->image_data }}" alt="" class="badge-card-element__img">
                @endif
                @break
            @case('prayer_pals_group')
                {{ $badge['prayerPals'] ?? '' }}
                @break
        @endswitch
    </div>
@endforeach
