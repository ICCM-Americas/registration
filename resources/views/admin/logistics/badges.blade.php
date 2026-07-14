@extends(config('registration.layout'))

@section('title')
{{ __('registration::admin.badges_title') }}
@endsection

@section('content')
    @include('registration::partials.report-toolbar', ['reportName' => 'badges'])

    <h1 class="no-print">{{ __('registration::admin.badges_title') }}</h1>

    <a href="{{ route($routeName('admin.logistics.badges.layout'), ['size' => $size->value]) }}" class="btn btn-outline-primary no-print mb-3">
        {{ __('registration::admin.badge_layout_edit_link') }}
    </a>

    <form method="GET" action="{{ route($routeName('admin.logistics.badges')) }}" class="no-print mb-3">
        <label class="font-weight-bold d-block mb-2">{{ __('registration::admin.badges_size_label') }}</label>
        <div class="form-row">
            @foreach ($sizes as $option)
                <div class="col-sm-6 col-md-3 mb-2">
                    <label class="card h-100 mb-0 p-2 badge-size-option {{ $option === $size ? 'border-primary bg-light' : '' }}">
                        <div class="form-check">
                            <input
                                class="form-check-input badge-size-radio"
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

    @if ($badges->isEmpty())
        <p class="text-muted">{{ __('registration::admin.badges_empty') }}</p>
    @endif

    @php
        [$cardWidthMm, $cardHeightMm] = $size->cardSizeMm();
        // Scaled against the original 4in x 3in badge card so smaller stock (business cards) keeps legible, non-overflowing text.
        $scale = min($cardWidthMm / 101.6, $cardHeightMm / 76.2);

        $ptToMm = 25.4 / 72;
        $paddingMm = round(0.15 * $scale * 25.4, 3);
        $headerFontPt = round(12 * $scale, 1);
        $nameFontPt = round(26 * $scale, 1);
        $orgFontPt = round(11 * $scale, 1);

        // Mirrors the PDF export's layout math exactly, so the on-screen
        // preview matches what actually prints: the name sits at the card's
        // true vertical center, and the organization sits half-way between
        // the name's bottom edge and the card's bottom.
        $nameLineHeightMm = round($nameFontPt * $ptToMm * 1.1, 3);
        $nameTopMm = round(($cardHeightMm - $nameLineHeightMm) / 2, 3);
        $nameBottomMm = $nameTopMm + $nameLineHeightMm;

        $orgLineHeightMm = round($orgFontPt * $ptToMm * 1.2, 3);
        $orgTopMm = round((($nameBottomMm + $cardHeightMm) / 2) - ($orgLineHeightMm / 2), 3);
    @endphp
    {{-- The only rule set on this screen that cannot live in registration.css:
         every measurement is derived above from the admin-configured badge
         size. The sheet publishes them as custom properties and the stylesheet
         consumes them, so the geometry is the only thing computed per request. --}}
    <style nonce="{{ $cspNonce ?? '' }}">
        .badge-sheet {
            --badge-card-w: {{ $cardWidthMm }}mm;
            --badge-card-h: {{ $cardHeightMm }}mm;
            --badge-pad: {{ $paddingMm }}mm;
            --badge-header-w: {{ round($cardWidthMm - 2 * $paddingMm, 3) }}mm;
            --badge-header-font: {{ $headerFontPt }}pt;
            --badge-name-top: {{ $nameTopMm }}mm;
            --badge-name-font: {{ $nameFontPt }}pt;
            --badge-org-top: {{ $orgTopMm }}mm;
            --badge-org-font: {{ $orgFontPt }}pt;
            --badge-logo-h: {{ round(0.45 * $scale, 3) }}in;
            --badge-logo-w: {{ round(1.5 * $scale, 3) }}in;
            --badge-logo-gap: {{ round(0.1 * $scale, 3) }}in;
        }
    </style>

    <div class="badge-sheet">
        @foreach ($badges as $badge)
            <div class="badge-card">
                @if ($elements->isEmpty())
                    <div class="badge-card__header">
                        @if ($branding->logoUrl())
                            <img src="{{ $branding->logoUrl() }}" alt="">
                        @endif
                        <span>{{ trim(($conferenceEdition->name() ?? $branding->siteName()).' '.$conferenceEdition->year()) }}</span>
                    </div>
                    <div class="badge-card__name">{{ $badge['name'] }}</div>
                    <div class="badge-card__organization">{{ $badge['organization'] }}</div>
                @else
                    @include('registration::partials.badge-card-elements', ['elements' => $elements, 'badge' => $badge])
                @endif
            </div>
        @endforeach
    </div>

    @php
        [$gridColumns, $gridRows] = $size->grid();
        [$marginXMm, $marginYMm] = $size->marginsMm();
        $pdfPayload = [
            'paper' => $size->pageSize(),
            'card' => [$cardWidthMm, $cardHeightMm],
            'grid' => [$gridColumns, $gridRows],
            'margins' => [$marginXMm, $marginYMm],
            'conference' => trim(($conferenceEdition->name() ?? $branding->siteName()).' '.$conferenceEdition->year()),
            'badges' => $badges->values(),
            'elements' => $elements->map(fn ($element): array => [
                'type' => $element->type->value,
                'x' => $element->x_pct,
                'y' => $element->y_pct,
                'width' => $element->width_pct,
                'align' => $element->align,
                'fontSize' => $element->font_size_pt,
                'bold' => $element->bold,
                'italic' => $element->italic,
                'text' => $element->text,
                'image' => $element->image_data ? 'data:'.$element->image_mime.';base64,'.$element->image_data : null,
            ])->values(),
        ];
    @endphp
    <script type="application/json" id="badges-pdf-data">@json($pdfPayload)</script>
    <script nonce="{{ $cspNonce ?? '' }}">
        {{-- The client-side PDF export (wired to the toolbar's Export PDF
             button): each card is placed at its absolute position on the Avery
             grid, in millimeters, so the print lines up with the die-cut stock
             exactly. The layout math mirrors the on-screen preview above. --}}
        document.querySelectorAll('.badge-card-element').forEach(function (el) {
            el.style.left = el.dataset.leftPct + '%';
            el.style.top = el.dataset.topPct + '%';
            el.style.width = el.dataset.widthPct + '%';
            el.style.textAlign = el.dataset.align;
            if (el.dataset.fontSizePt) {
                el.style.fontSize = el.dataset.fontSizePt + 'pt';
                el.style.lineHeight = '1.1';
                el.style.fontWeight = el.dataset.bold === '1' ? 'bold' : '';
                el.style.fontStyle = el.dataset.italic === '1' ? 'italic' : '';
            }
        });

        window.conferenceReportPdf = async function () {
            const data = JSON.parse(document.getElementById('badges-pdf-data').textContent);
            const PT_TO_MM = 25.4 / 72;
            const [cardW, cardH] = data.card;
            const [columns, rows] = data.grid;
            const [marginX, marginY] = data.margins;
            const perSheet = columns * rows;
            // Same rule as the preview: font sizes scale against the original
            // 4x3in badge card so business-card stock keeps legible text.
            const scale = Math.min(cardW / 101.6, cardH / 76.2);
            const padding = 0.15 * scale * 25.4;

            const doc = await conferencePdf.createDoc({ format: data.paper });
            const logo = await conferencePdf.imageData(conferencePdf.branding.logo);
            const elementImages = await Promise.all(data.elements.map(
                (element) => element.image ? conferencePdf.imageData(element.image) : Promise.resolve(null),
            ));

            data.badges.forEach((badge, index) => {
                if (index > 0 && index % perSheet === 0) {
                    doc.addPage();
                }
                const slot = index % perSheet;
                drawCard(badge, marginX + (slot % columns) * cardW, marginY + Math.floor(slot / columns) * cardH);
            });

            conferencePdf.save(doc, 'badges');

            function drawCard(badge, x, y) {
                // The same dashed cutting guide the preview shows.
                doc.setDrawColor('#999999');
                doc.setLineWidth(0.2);
                doc.setLineDashPattern([1, 1], 0);
                doc.rect(x, y, cardW, cardH);
                doc.setLineDashPattern([], 0);

                if (data.elements.length) {
                    data.elements.forEach((element, i) => drawElement(element, elementImages[i], badge, x, y));
                } else {
                    drawDefaultLayout(badge, x, y);
                }
            }

            function drawDefaultLayout(badge, x, y) {
                const headerPt = 12 * scale;
                const namePt = 26 * scale;
                const orgPt = 11 * scale;

                // Header: logo up to 0.45x1.5in (scaled), conference name beside
                // it, both vertically centered on each other.
                let textX = x + padding;
                let headerMiddle = y + padding + headerPt * PT_TO_MM * 0.6;
                if (logo) {
                    const maxW = 1.5 * scale * 25.4;
                    const maxH = 0.45 * scale * 25.4;
                    let w = maxW;
                    let h = (maxW * logo.height) / logo.width;
                    if (h > maxH) {
                        h = maxH;
                        w = (maxH * logo.width) / logo.height;
                    }
                    doc.addImage(logo.data, 'PNG', x + padding, y + padding, w, h);
                    textX += w + 0.1 * scale * 25.4;
                    headerMiddle = y + padding + h / 2;
                }
                doc.setFontSize(headerPt);
                doc.text(data.conference, textX, headerMiddle, { baseline: 'middle' });

                // The name sits at the card's true vertical center; the
                // organization half-way between the name's bottom edge and the
                // card's bottom — the same math as the preview.
                const nameLineH = namePt * PT_TO_MM * 1.1;
                const nameTop = (cardH - nameLineH) / 2;
                doc.setFont('DejaVuSans', 'bold');
                doc.setFontSize(namePt);
                const name = badge.name || '';
                const nameWidth = doc.getTextWidth(name);
                const nameMax = cardW - 2 * padding;
                if (nameWidth > nameMax) {
                    // Shrink to fit rather than run over the card edge.
                    doc.setFontSize((namePt * nameMax) / nameWidth);
                }
                doc.text(name, x + cardW / 2, y + nameTop + nameLineH / 2, { align: 'center', baseline: 'middle' });

                const orgLineH = orgPt * PT_TO_MM * 1.2;
                const orgTop = (nameTop + nameLineH + cardH) / 2 - orgLineH / 2;
                doc.setFont('DejaVuSans', 'italic');
                doc.setFontSize(orgPt);
                doc.text(badge.organization || '', x + cardW / 2, y + orgTop + orgLineH / 2, { align: 'center', baseline: 'middle' });
                doc.setFont('DejaVuSans', 'normal');
            }

            function drawElement(element, image, badge, x, y) {
                const ex = x + (element.x / 100) * cardW;
                const ey = y + (element.y / 100) * cardH;
                const ew = (element.width / 100) * cardW;

                if (element.type === 'logo' || element.type === 'static_image') {
                    const img = element.type === 'logo' ? logo : image;
                    if (img) {
                        doc.addImage(img.data, 'PNG', ex, ey, ew, (ew * img.height) / img.width);
                    }
                    return;
                }

                const content = {
                    badge_name: badge.name,
                    conference_name: data.conference,
                    organization: badge.organization,
                    static_text: element.text,
                    prayer_pals_group: badge.prayerPals,
                }[element.type];
                if (!content) {
                    return;
                }

                doc.setFont('DejaVuSans', element.bold && element.italic ? 'bolditalic' : element.bold ? 'bold' : element.italic ? 'italic' : 'normal');
                doc.setFontSize(element.fontSize);
                const anchor = element.align === 'center' ? ex + ew / 2 : element.align === 'right' ? ex + ew : ex;
                doc.text(doc.splitTextToSize(String(content), ew), anchor, ey, { align: element.align, baseline: 'top', lineHeightFactor: 1.1 });
                doc.setFont('DejaVuSans', 'normal');
            }
        };
    </script>
@endsection
