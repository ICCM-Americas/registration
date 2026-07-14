@extends(config('registration.layout'))

@section('title')
{{ __('registration::admin.shuttles_title') }}
@endsection

@section('content')
    @include('registration::partials.report-toolbar', ['reportName' => 'shuttles'])


    <h1>{{ __('registration::admin.shuttles_title') }}</h1>
    <p class="text-muted no-print">{{ __('registration::admin.shuttles_intro', ['seats' => $planner->seats(), 'count' => $planner->shuttleCount(), 'travel' => $planner->travelMinutes()]) }}</p>

    @if ($arrivalRuns->isEmpty() && $returnRuns->isEmpty())
        <p class="text-muted">{{ __('registration::admin.shuttles_none') }}</p>
    @endif

    @foreach ([
        [__('registration::admin.shuttles_pickups'), 'arrival', $arrivalRuns],
        [__('registration::admin.shuttles_returns'), 'departure', $returnRuns],
    ] as [$heading, $flight, $days])
        @if ($days->isNotEmpty())
            <h2>{{ $heading }}</h2>

            @foreach ($days as $day)
                <div class="card mb-3 iccm-avoid-break">
                    @if ($day['label'] !== '')
                        <div class="card-header">
                            <strong>{{ $day['label'] }}</strong>
                            — {{ trans_choice('registration::admin.shuttles_passengers', $day['count']) }}
                        </div>
                    @endif
                    <div class="card-body">
                        @foreach ($day['runs'] as $run)
                            <div class="mb-2">
                                <strong>{{ __('registration::admin.shuttles_run_at', ['time' => $run['time']]) }}</strong>
                                — {{ trans_choice('registration::admin.shuttles_passengers', $run['passengers']->count()) }}
                                @if (($run['shuttles'] ?? 1) > 1)
                                    — {{ trans_choice('registration::admin.shuttles_vehicles', $run['shuttles']) }}
                                @endif
                                <div>
                                    {{ $planner->passengerList($run['passengers'], $flight, $guestQuestions, $questions) }}
                                </div>
                                @if (($run['overflow'] ?? collect())->isNotEmpty())
                                    {{-- Beyond the fleet's capacity for this run; a valid flight time, just no
                                         seat — the flight editor link still opens their travel details. --}}
                                    <div class="text-danger">
                                        {{ __('registration::admin.shuttles_overflow') }}
                                        @foreach ($run['overflow'] as $passenger)
                                            <a href="{{ route($routeName('admin.logistics.shuttles.flight'), [$flight, $passenger instanceof \ConferenceTools\Registration\Models\Guest ? $passenger->user_id : $passenger->getKey()]) }}"
                                               class="js-editor-link text-danger shuttles-flight-link"
                                               title="{{ __('registration::admin.shuttles_flight_edit') }}">{{ $guestQuestions->occupantFullName($passenger, $questions) }}</a>@if (! $loop->last), @endif
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @endforeach

                        @if ($day['unscheduled']->isNotEmpty())
                            {{-- Each name opens its entered answer in the editor
                                 modal for fixing; a guest rides on their
                                 registrant's flights, so a guest's name opens
                                 the registrant's answer. --}}
                            <div class="text-danger">
                                {{ __('registration::admin.shuttles_unscheduled') }}
                                @foreach ($day['unscheduled'] as $passenger)
                                    <a href="{{ route($routeName('admin.logistics.shuttles.flight'), [$flight, $passenger instanceof \ConferenceTools\Registration\Models\Guest ? $passenger->user_id : $passenger->getKey()]) }}"
                                       class="js-editor-link text-danger shuttles-flight-link"
                                       title="{{ __('registration::admin.shuttles_flight_edit') }}">{{ $guestQuestions->occupantFullName($passenger, $questions) }}</a>@if (! $loop->last), @endif
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach
        @endif
    @endforeach

    @if ($arrivalRuns->isNotEmpty() || $returnRuns->isNotEmpty())
        <div class="card mb-3 iccm-avoid-break">
            <div class="card-body d-flex justify-content-between align-items-center">
                <strong>{{ __('registration::admin.report_count_label') }}</strong>
                <span>{{ $totalPassengers }}</span>
            </div>
        </div>
    @endif

    @include('registration::partials.editor-modal')

    <script type="application/json" id="report-pdf-data">@json($pdfPayload)</script>
    <script nonce="{{ $cspNonce ?? '' }}">
        {{-- The client-side PDF export (wired to the toolbar's Export PDF
             button): pickups then returns, each day a filled bar in the
             primary color, each run a bold headline over its passenger
             list — mirroring the on-screen cards. --}}
        window.conferenceReportPdf = async function () {
            const data = JSON.parse(document.getElementById('report-pdf-data').textContent);
            const margin = conferencePdf.PAGE_MARGIN;
            const branding = conferencePdf.branding;
            const doc = await conferencePdf.createDoc({ format: data.paper });
            let y = await conferencePdf.drawHeader(doc, { title: data.title });

            const pageWidth = doc.internal.pageSize.getWidth();
            const pageHeight = doc.internal.pageSize.getHeight();
            const width = pageWidth - 2 * margin;
            const lineHeight = (pt) => pt * 0.3528 * 1.25;
            const ensure = (needed) => {
                if (y + needed > pageHeight - margin) {
                    doc.addPage();
                    y = margin;
                }
            };
            const writeLines = (text, pt, style, color) => {
                doc.setFontSize(pt);
                doc.setFont('DejaVuSans', style);
                doc.setTextColor(color || branding.text);
                doc.splitTextToSize(text, width).forEach((line) => {
                    ensure(lineHeight(pt));
                    doc.text(line, margin, y, { baseline: 'top' });
                    y += lineHeight(pt);
                });
                doc.setTextColor(branding.text);
            };

            data.sections.forEach((section) => {
                ensure(20);
                writeLines(section.heading, 14, 'bold');
                y += 3;

                section.days.forEach((day) => {
                    if (day.label) {
                        ensure(18);
                        doc.setFillColor(branding.primary);
                        doc.rect(margin, y, width, 8, 'F');
                        doc.setFontSize(12);
                        doc.setFont('DejaVuSans', 'bold');
                        doc.setTextColor('#ffffff');
                        doc.text(day.label, margin + 2, y + 4, { baseline: 'middle' });
                        doc.setTextColor(branding.text);
                        y += 10;
                    }

                    day.runs.forEach((run) => {
                        writeLines(run.headline, 11, 'bold');
                        writeLines(run.names, 11, 'normal');
                        if (run.overflow) {
                            writeLines(run.overflow, 11, 'normal', '#b00020');
                        }
                        y += 2;
                    });

                    if (day.unscheduled) {
                        writeLines(day.unscheduled, 11, 'normal', '#b00020');
                    }
                    y += 3;
                });
            });

            if (data.sections.length) {
                ensure(20);
                writeLines(data.countLabel + ': ' + data.count, 12, 'bold');
            }

            conferencePdf.save(doc, data.filename);
        };
    </script>
@endsection
