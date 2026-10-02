{{--
    Warns before a .js-answer-sync form saves an edit that rewrites stored
    answers. On submit the form is first posted with preview=1, which answers
    {changes: n} without saving; when n > 0 this dialog asks before the real
    submit, otherwise the form submits straight away. Cancel abandons the save.

    The listener runs in the capture phase so it sees the submit before
    partials/editor-modal.blade.php turns a modal form's submit into AJAX.
--}}
<div class="modal reg-modal-overlay" tabindex="-1" role="dialog" id="answer-sync-modal">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title mb-0">{{ __('registration::admin.answer_sync_title') }}</h5>
            </div>
            <div class="modal-body">
                <p>{!! __('registration::admin.answer_sync_body', ['count' => '<strong class="js-answer-sync-count"></strong>']) !!}</p>
                <p class="mb-0">{{ __('registration::admin.answer_sync_warning') }}</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary js-answer-sync-cancel">{{ __('registration::admin.cancel') }}</button>
                <button type="button" class="btn btn-primary js-answer-sync-ok">{{ __('registration::admin.answer_sync_ok') }}</button>
            </div>
        </div>
    </div>
</div>

<script nonce="{{ $cspNonce ?? '' }}">
(function () {
    var modal = document.getElementById('answer-sync-modal');
    var count = modal.querySelector('.js-answer-sync-count');
    var pending = null;

    function open(form, changes) {
        pending = form;
        count.textContent = changes;
        modal.classList.add('is-open');
        document.body.classList.add('modal-open');
    }

    function close() {
        pending = null;
        modal.classList.remove('is-open');
        if (!document.querySelector('.reg-modal-overlay.is-open')) {
            document.body.classList.remove('modal-open');
        }
    }

    function proceed(form) {
        form.dataset.answerSyncConfirmed = '1';
        form.requestSubmit();
    }

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form.classList.contains('js-answer-sync')) return;
        if (form.dataset.answerSyncConfirmed) {
            delete form.dataset.answerSyncConfirmed;

            return;
        }
        e.preventDefault();
        e.stopImmediatePropagation();

        var data = new FormData(form);
        data.append('preview', '1');
        // A failed preview (e.g. a validation error) submits for real, so the
        // server's own response shows what went wrong.
        fetch(form.action, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: data
        }).then(function (r) {
            return r.ok ? r.json() : { changes: 0 };
        }).then(function (result) {
            if (result.changes > 0) {
                open(form, result.changes);
            } else {
                proceed(form);
            }
        }).catch(function () {
            proceed(form);
        });
    }, true);

    modal.addEventListener('click', function (e) {
        if (e.target.closest('.js-answer-sync-ok')) {
            var form = pending;
            close();
            proceed(form);
        } else if (e.target.closest('.js-answer-sync-cancel') || e.target === modal) {
            close();
        }
    });

    // Captured so Escape closes only this dialog, not an editor modal under it.
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal.classList.contains('is-open')) {
            e.stopImmediatePropagation();
            close();
        }
    }, true);
})();
</script>
