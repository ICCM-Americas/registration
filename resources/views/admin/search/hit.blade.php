{{--
    One admin search result: the title links to where the match is edited
    (an AJAX editor in the shared modal when $hit->modal), the snippet shows
    the matched text with each match in bold. An answer hit also carries a
    selection checkbox and its own Delete… button.

    Expects: $hit (SearchHit), $returnTo (these results' URL, carried by
    page links so the editor they open comes back here).
--}}
@php
    use ConferenceTools\Registration\Support\Search\SearchHit;
@endphp
<li class="list-group-item">
    <div class="d-flex align-items-start iccm-gap">
        @if ($hit->target)
            <input type="checkbox" class="reg-search-check js-search-target" value="{{ $hit->target->encode() }}"
                   aria-label="{{ __('registration::admin.search_select', ['name' => $hit->title]) }}">
        @endif
        <div class="reg-search-hit">
            <div class="iccm-row iccm-row-tight">
                <a href="{{ SearchHit::returning($hit->url, $hit->modal, $returnTo) }}" class="{{ $hit->modal ? 'js-editor-link' : '' }}">{{ $hit->title }}</a>
                @if ($hit->key)<code>{{ $hit->key }}</code>@endif
                <span class="badge badge-info">{{ $hit->field }}</span>
                @if ($hit->locale)<span class="badge badge-primary">{{ $hit->locale }}</span>@endif
                @foreach ($hit->badges as $badge)
                    <span class="badge badge-secondary">{{ $badge }}</span>
                @endforeach
            </div>
            @if ($hit->context)
                <div class="text-muted small">{{ $hit->context }}</div>
            @endif
            <div class="reg-search-snippet">
                {{ $hit->snippet->html }}
                @if ($hit->snippet->more > 0)
                    <span class="text-muted small">{{ __('registration::admin.search_more_matches', ['count' => $hit->snippet->more]) }}</span>
                @endif
            </div>
            @if ($hit->links || $hit->target)
                <div class="iccm-row iccm-row-tight mt-1">
                    @foreach ($hit->links as $link)
                        <a href="{{ SearchHit::returning($link['url'], $link['modal'], $returnTo) }}" class="btn btn-sm btn-outline-secondary {{ $link['modal'] ? 'js-editor-link' : '' }}">{{ $link['label'] }}</a>
                    @endforeach
                    @if ($hit->target)
                        <a href="{{ route($routeName('admin.search.registrations.preview'), ['targets' => [$hit->target->encode()]]) }}"
                           class="btn btn-sm btn-outline-danger js-editor-link">{{ __('registration::admin.search_delete_one') }}</a>
                    @endif
                </div>
            @endif
        </div>
    </div>
</li>
