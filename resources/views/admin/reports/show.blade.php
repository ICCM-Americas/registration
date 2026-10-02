@extends(config('registration.layout'))

@section('title')
{{ $report->name }}
@endsection

@section('content')
    @include('registration::partials.report-toolbar', [
        'reportName' => 'defined',
        'csvUrl' => route($routeName('admin.reports.csv'), $report),
        'pdfPaper' => $pdfPaper,
    ])


    <h1>{{ trim(($conferenceEdition->name() ?? $branding->siteName()).' '.$conferenceEdition->year()) }} — {{ $report->name }}</h1>
    @if ($report->description)
        <p class="text-muted no-print">{{ $report->description }}</p>
    @endif
    @if ($report->header)
        <p>{{ $vars($report->header) }}</p>
    @endif

    @if ($rows->isEmpty())
        <p class="text-muted">{{ __('registration::admin.report_no_rows') }}</p>
    @else
        <div class="iccm-scroll-x">
            <table class="table table-sm">
                <thead>
                    <tr>
                        @foreach ($headers as $header)
                            <th>{{ $header }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            @foreach ($row as $cell)
                                <td>{{ $cell }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                    <tr class="table-active">
                        <th>{{ __('registration::admin.report_count_label') }}</th>
                        <td colspan="{{ max(count($headers) - 1, 1) }}">{{ $rows->count() }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    @endif
    @if ($report->footer)
        <p>{{ $vars($report->footer) }}</p>
    @endif

    @include('registration::partials.report-pdf-script')
@endsection
