
<div class="modal reg-modal-overlay" tabindex="-1" role="dialog" id="cost-change-modal">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title mb-0">{{ __('registration::admin.payments_cost_change_title') }}</h5>
            </div>
            <div class="modal-body">
                <p class="mb-0">
                    {!! __('registration::admin.payments_cost_change_body', [
                        'old' => '<strong class="js-cost-old"></strong>',
                        'new' => '<strong class="js-cost-new"></strong>',
                    ]) !!}
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary js-cost-change-cancel">{{ __('registration::admin.payments_cost_change_cancel') }}</button>
                <button type="button" class="btn btn-primary js-cost-change-ok">{{ __('registration::admin.payments_cost_change_ok') }}</button>
            </div>
        </div>
    </div>
</div>
