{{-- Asks for the PDF export's orientation and paper size, preselecting portrait and $pdfPaper. --}}
<div class="modal reg-modal-overlay" tabindex="-1" role="dialog" id="pdf-options-modal" aria-labelledby="pdf-options-title">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title mb-0" id="pdf-options-title">{{ __('registration::admin.pdf_options_title') }}</h5>
            </div>
            <div class="modal-body">
                <fieldset>
                    <legend class="h6">{{ __('registration::admin.pdf_orientation_title') }}</legend>
                    @foreach (['portrait' => __('registration::admin.pdf_orientation_portrait'), 'landscape' => __('registration::admin.pdf_orientation_landscape')] as $orientation => $label)
                        <label class="iccm-checkbox-row">
                            <input type="radio" name="pdf_orientation" value="{{ $orientation }}" @checked($orientation === 'portrait')>
                            {{ $label }}
                        </label>
                    @endforeach
                </fieldset>
                <fieldset class="mt-3">
                    <legend class="h6">{{ __('registration::admin.pdf_paper_title') }}</legend>
                    @foreach (['letter' => __('registration::admin.pdf_paper_letter'), 'a4' => __('registration::admin.pdf_paper_a4')] as $paper => $label)
                        <label class="iccm-checkbox-row">
                            <input type="radio" name="pdf_paper" value="{{ $paper }}" @checked($pdfPaper === $paper)>
                            {{ $label }}
                        </label>
                    @endforeach
                </fieldset>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary js-pdf-options-cancel">{{ __('registration::admin.cancel') }}</button>
                <button type="button" class="btn btn-primary js-pdf-options-export">{{ __('registration::admin.pdf_options_export') }}</button>
            </div>
        </div>
    </div>
</div>
