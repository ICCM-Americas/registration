
<div class="modal reg-modal-overlay" tabindex="-1" role="dialog" id="editor-modal">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"></h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="{{ __('registration::admin.close') }}"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body"></div>
        </div>
    </div>
</div>

<script nonce="{{ $cspNonce ?? '' }}">
(function () {
    var modal = document.getElementById('editor-modal');
    var title = modal.querySelector('.modal-title');
    var body = modal.querySelector('.modal-body');
    var row = null; // the .js-badge-row the open editor was launched from

    function render(html) {
        body.innerHTML = html;
        var editor = body.querySelector('.js-editor');
        if (editor) title.textContent = editor.dataset.title || '';
    }

    function fail(message) {
        var box = body.querySelector('.js-editor-errors') || body;
        box.innerHTML = '<div class="alert alert-danger"></div>';
        box.firstChild.textContent = message;
    }

    function open(link) {
        row = link.closest('.js-badge-row');
        title.textContent = '';
        body.innerHTML = '';
        modal.classList.add('is-open');
        document.body.classList.add('modal-open');
        fetch(link.href, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) {
                if (!r.ok) throw new Error(r.status);
                return r.text().then(render);
            })
            .catch(function () { fail(@json(__('registration::admin.editor_failed'))); });
    }

    function close() {
        var editor = body.querySelector('.js-editor');
        if (editor && editor.dataset.reload) {
            window.location.reload();

            return;
        }
        if (row && editor && editor.dataset.tags) {
            var tags = JSON.parse(editor.dataset.tags);
            Object.keys(tags).forEach(function (name) {
                var badge = row.querySelector('[data-badge="' + name + '"]');
                if (badge) badge.classList.toggle('d-none', !tags[name]);
            });
        }
        row = null;
        modal.classList.remove('is-open');
        document.body.classList.remove('modal-open');
    }

    document.addEventListener('click', function (e) {
        var link = e.target.closest('.js-editor-link');
        if (link) {
            e.preventDefault();
            open(link);

            return;
        }
        if (e.target.closest('[data-dismiss="modal"]') || e.target === modal) close();
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal.classList.contains('is-open')) close();
    });

    modal.addEventListener('change', function (e) {
        if (e.target.classList.contains('js-auto-submit')) {
            e.target.form.requestSubmit();
        }
        if (e.target.classList.contains('js-scope-pick')) {
            e.target.form.querySelectorAll('.js-scope-select').forEach(function (select) {
                var picked = select.dataset.scope === e.target.value;
                select.disabled = !picked;
                select.classList.toggle('d-none', !picked);
            });
        }
    });

    // Submit every form inside the modal over AJAX and swap in the refreshed
    // editor the server responds with. Accept must be JSON alone: that is what
    // makes Laravel answer a failed validation with 422 JSON instead of a
    // redirect — a success still returns the HTML fragment regardless.
    modal.addEventListener('submit', function (e) {
        var confirmForm = e.target.closest('.js-confirm-submit');
        if (confirmForm && !confirm(confirmForm.dataset.confirm)) {
            e.preventDefault();

            return;
        }
        if (e.defaultPrevented) return;
        e.preventDefault();
        var form = e.target;
        fetch(form.action, {
            method: 'POST', // the _method spoof field rides along in the form data
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: new FormData(form)
        }).then(function (r) {
            if (r.ok) return r.text().then(render);
            if (r.status === 422) return r.json().then(function (data) {
                fail(Object.values(data.errors || {}).map(function (messages) { return messages.join(' '); }).join(' '));
            });
            throw new Error(r.status);
        }).catch(function () {
            fail(@json(__('registration::admin.editor_failed')));
        });
    });
})();
</script>
