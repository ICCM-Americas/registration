@extends(config('registration.layout'))

@section('title')
{{ __('registration::admin.badge_layout_title') }}
@endsection

@section('content')

    <h1>{{ __('registration::admin.badge_layout_title') }}</h1>
    <p class="text-muted">{{ __('registration::admin.badge_layout_intro') }}</p>

    <a href="{{ route($routeName('admin.logistics.badges'), ['size' => $size->value]) }}" class="btn btn-outline-secondary mb-3">
        {{ __('registration::admin.badge_layout_back_link') }}
    </a>

    @if ($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="GET" action="{{ route($routeName('admin.logistics.badges.layout')) }}" class="mb-3">
        <label class="font-weight-bold d-block mb-2">{{ __('registration::admin.badges_size_label') }}</label>
        <div class="form-row">
            @foreach ($sizes as $option)
                <div class="col-sm-6 col-md-3 mb-2">
                    <label class="card h-100 mb-0 p-2 badge-layout-size-card {{ $option === $size ? 'border-primary bg-light' : '' }}">
                        <div class="form-check">
                            <input
                                class="form-check-input js-auto-submit"
                                type="radio"
                                name="size"
                                value="{{ $option->value }}"
                                {{ $option === $size ? 'checked' : '' }}
                            >
                            <span class="form-check-label">{{ $option->label() }}</span>
                        </div>
                    </label>
                </div>
            @endforeach
        </div>
    </form>

    @if ($elements->isEmpty())
        <div class="alert alert-secondary d-flex flex-wrap justify-content-between align-items-center iccm-gap">
            <span>{{ __('registration::admin.badge_layout_empty') }}</span>
            <form method="POST" action="{{ route($routeName('admin.logistics.badges.layout.seed'), ['size' => $size->value]) }}">
                @csrf
                <button type="submit" class="btn btn-sm btn-primary">{{ __('registration::admin.badge_layout_load_defaults') }}</button>
            </form>
        </div>
    @endif

    @php
        [$cardWidthMm, $cardHeightMm] = $size->cardSizeMm();
        $canvasWidth = 500;
        $canvasHeight = round($canvasWidth * $cardHeightMm / $cardWidthMm);
    @endphp

    <div id="badge-layout-status" class="mb-2 iccm-status-line"></div>

    <div
        id="badge-layout-canvas"
        class="badge-layout-canvas"
        data-position-url-template="{{ route($routeName('admin.logistics.badges.layout.position'), ['element' => '__ID__']) }}"
        data-csrf="{{ csrf_token() }}"
        data-canvas-width="{{ $canvasWidth }}"
        data-canvas-height="{{ $canvasHeight }}"
    >
        @foreach ($elements as $element)
            <div
                class="badge-layout-element"
                data-element-id="{{ $element->id }}"
                data-x-pct="{{ $element->x_pct }}"
                data-y-pct="{{ $element->y_pct }}"
                data-width-pct="{{ $element->width_pct }}"
            >
                {{ $element->type->label() }}
            </div>
        @endforeach
    </div>

    <div class="row">
        @foreach ($elements as $element)
            <div class="col-md-6 mb-3">
                <div class="card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <strong>{{ $element->type->label() }}</strong>
                        <form method="POST" action="{{ route($routeName('admin.logistics.badges.layout.destroy'), $element) }}" class="js-confirm-submit" data-confirm="{{ __('registration::admin.badge_layout_delete_confirm') }}">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn-sm btn-danger">{{ __('registration::admin.delete') }}</button>
                        </form>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="{{ route($routeName('admin.logistics.badges.layout.update'), $element) }}" enctype="multipart/form-data">
                            @csrf @method('PUT')

                            <div class="form-group">
                                <label>{{ __('registration::admin.badge_layout_field_width') }}</label>
                                <input type="number" min="1" max="100" step="1" name="width_pct" value="{{ old('width_pct', $element->width_pct) }}" class="form-control">
                            </div>

                            <div class="form-group">
                                <label>{{ __('registration::admin.badge_layout_field_align') }}</label>
                                @php
                                    $alignLabels = [
                                        'left' => __('registration::admin.badge_layout_align_left'),
                                        'center' => __('registration::admin.badge_layout_align_center'),
                                        'right' => __('registration::admin.badge_layout_align_right'),
                                    ];
                                @endphp
                                <select name="align" class="form-control">
                                    @foreach ($alignLabels as $align => $alignLabel)
                                        <option value="{{ $align }}" {{ $element->align === $align ? 'selected' : '' }}>
                                            {{ $alignLabel }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            @if ($element->type->isTextual())
                                <div class="form-group">
                                    <label>{{ __('registration::admin.badge_layout_field_font_size') }}</label>
                                    <input type="number" min="6" max="72" step="1" name="font_size_pt" value="{{ old('font_size_pt', $element->font_size_pt) }}" class="form-control">
                                </div>
                                <div class="form-check mb-2">
                                    <input type="checkbox" name="bold" value="1" class="form-check-input" id="bold-{{ $element->id }}" {{ $element->bold ? 'checked' : '' }}>
                                    <label class="form-check-label" for="bold-{{ $element->id }}">{{ __('registration::admin.badge_layout_field_bold') }}</label>
                                </div>
                                <div class="form-check mb-2">
                                    <input type="checkbox" name="italic" value="1" class="form-check-input" id="italic-{{ $element->id }}" {{ $element->italic ? 'checked' : '' }}>
                                    <label class="form-check-label" for="italic-{{ $element->id }}">{{ __('registration::admin.badge_layout_field_italic') }}</label>
                                </div>
                            @endif

                            @if ($element->type->value === 'static_text')
                                <div class="form-group">
                                    <label>{{ __('registration::admin.badge_layout_field_text') }}</label>
                                    <textarea name="text" class="form-control" maxlength="500">{{ old('text', $element->text) }}</textarea>
                                </div>
                            @endif

                            @if ($element->type->value === 'static_image')
                                <div class="form-group">
                                    <label>{{ __('registration::admin.badge_layout_field_image') }}</label>
                                    <input type="file" name="image" accept="image/*" class="form-control-file">
                                    @if ($element->image_data)
                                        <img src="data:{{ $element->image_mime }};base64,{{ $element->image_data }}" alt="" class="badge-layout-image-preview">
                                    @endif
                                </div>
                            @endif

                            <button type="submit" class="btn btn-primary btn-sm">{{ __('registration::admin.save') }}</button>
                        </form>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="card">
        <div class="card-header"><strong>{{ __('registration::admin.badge_layout_add_element') }}</strong></div>
        <div class="card-body d-flex flex-wrap iccm-gap">
            @foreach (\ConferenceTools\Registration\Enums\BadgeElementType::cases() as $type)
                @php
                    $count = $elements->where('type', $type)->count();
                    $max = $type->maxPerSize();
                @endphp
                <form method="POST" action="{{ route($routeName('admin.logistics.badges.layout.store')) }}">
                    @csrf
                    <input type="hidden" name="sheet_size" value="{{ $size->value }}">
                    <input type="hidden" name="type" value="{{ $type->value }}">
                    <button type="submit" class="btn btn-outline-primary btn-sm" {{ $count >= $max ? 'disabled' : '' }}>
                        {{ $type->label() }} ({{ $count }}/{{ $max }})
                    </button>
                </form>
            @endforeach
        </div>
    </div>

    <script nonce="{{ $cspNonce ?? '' }}">
    (function () {
        document.querySelectorAll('.js-confirm-submit').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                if (!confirm(form.dataset.confirm)) {
                    e.preventDefault();
                }
            });
        });

        document.querySelectorAll('.js-auto-submit').forEach(function (el) {
            el.addEventListener('change', function () {
                el.form.requestSubmit();
            });
        });

        var canvas = document.getElementById('badge-layout-canvas');
        if (!canvas) return;

        canvas.style.width = canvas.dataset.canvasWidth + 'px';
        canvas.style.height = canvas.dataset.canvasHeight + 'px';

        canvas.querySelectorAll('.badge-layout-element').forEach(function (box) {
            box.style.left = box.dataset.xPct + '%';
            box.style.top = box.dataset.yPct + '%';
            box.style.width = box.dataset.widthPct + '%';
        });

        var urlTemplate = canvas.dataset.positionUrlTemplate;
        var csrf = canvas.dataset.csrf;
        var statusEl = document.getElementById('badge-layout-status');

        function status(key, ok) {
            statusEl.innerHTML = '<span class="badge badge-' + (ok ? 'success' : 'danger') + '">' + key + '</span>';
        }

        function savePosition(elementId, xPct, yPct) {
            fetch(urlTemplate.replace('__ID__', elementId), {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                body: JSON.stringify({ x_pct: xPct, y_pct: yPct }),
            }).then(function (r) {
                status(r.ok ? @json(__('registration::admin.badge_layout_position_saved')) : @json(__('registration::admin.badge_layout_position_save_failed')), r.ok);
            }).catch(function () {
                status(@json(__('registration::admin.badge_layout_position_save_failed')), false);
            });
        }

        var dragging = null;

        canvas.querySelectorAll('.badge-layout-element').forEach(function (box) {
            box.addEventListener('mousedown', function (e) {
                dragging = {
                    box: box,
                    startX: e.clientX,
                    startY: e.clientY,
                    startLeft: box.offsetLeft,
                    startTop: box.offsetTop,
                };
                e.preventDefault();
            });
        });

        document.addEventListener('mousemove', function (e) {
            if (!dragging) return;
            var rect = canvas.getBoundingClientRect();
            var box = dragging.box;
            var left = dragging.startLeft + (e.clientX - dragging.startX);
            var top = dragging.startTop + (e.clientY - dragging.startY);
            left = Math.max(0, Math.min(left, rect.width - box.offsetWidth));
            top = Math.max(0, Math.min(top, rect.height - box.offsetHeight));
            box.style.left = (left / rect.width * 100) + '%';
            box.style.top = (top / rect.height * 100) + '%';
        });

        document.addEventListener('mouseup', function () {
            if (!dragging) return;
            var box = dragging.box;
            var xPct = parseFloat(box.style.left);
            var yPct = parseFloat(box.style.top);
            dragging = null;
            savePosition(box.dataset.elementId, xPct, yPct);
        });
    })();
    </script>
@endsection
