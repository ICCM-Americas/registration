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

@isset($pdfPaper)
    @include('registration::partials.pdf-options-modal')
@endisset

<script nonce="{{ $cspNonce ?? '' }}">
(function () {
    var modal = document.getElementById('pdf-options-modal');

    function closeModal() {
        modal.classList.remove('is-open');
        document.body.classList.remove('modal-open');
    }

    document.querySelectorAll('.js-export-pdf').forEach(function (button) {
        button.addEventListener('click', function () {
            if (!modal) {
                conferencePdf.run(conferenceReportPdf);

                return;
            }
            modal.classList.add('is-open');
            document.body.classList.add('modal-open');
        });
    });

    if (!modal) return;

    modal.addEventListener('click', function (e) {
        if (e.target.closest('.js-pdf-options-export')) {
            var options = {
                orientation: modal.querySelector('input[name="pdf_orientation"]:checked').value,
                paper: modal.querySelector('input[name="pdf_paper"]:checked').value,
            };
            closeModal();
            conferencePdf.run(function () { return conferenceReportPdf(options); });
        } else if (e.target.closest('.js-pdf-options-cancel') || e.target === modal) {
            closeModal();
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal.classList.contains('is-open')) closeModal();
    });
})();
</script>
