
<div class="no-print">
    @include('registration::partials.admin-nav')

    <div class="iccm-toolbar">
        @isset($reportName)
            @unless($csvOnly ?? false)
                @include('branding::partials.pdf-scripts')
                <button type="button" class="btn btn-outline-secondary js-export-pdf">{{ __('registration::admin.export_pdf') }}</button>
            @endunless
            @unless($pdfOnly ?? false)
                <a href="{{ $csvUrl ?? route($routeName('admin.logistics.'.$reportName.'.csv')) }}" class="btn btn-outline-secondary">{{ __('registration::admin.export_csv') }}</a>
            @endunless
        @endisset
    </div>
</div>

<script nonce="{{ $cspNonce ?? '' }}">
document.querySelectorAll('.js-export-pdf').forEach(function (button) {
    button.addEventListener('click', function () {
        conferencePdf.run(conferenceReportPdf);
    });
});
</script>
