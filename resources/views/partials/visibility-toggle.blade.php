{{-- Within-step visibility toggle: show/hide questions whose data-visible-when
     rule passes for the current answers, and disable hidden inputs so they
     neither submit nor block submission. This is a live convenience only — every
     rule (within-step and cross-step) is re-evaluated and enforced on the server,
     so editing this script cannot reveal a hidden field or skip a required one.

     Expects a form with id="registration-form". --}}
<script nonce="{{ $cspNonce ?? '' }}">
(function () {
    var form = document.getElementById('registration-form');
    if (!form) return;

    function answers() {
        var map = {};
        form.querySelectorAll('[name]').forEach(function (el) {
            if (el.disabled || !el.name) return;
            var key = el.name.replace(/\[\]$/, '');
            if (el.type === 'checkbox') {
                if (el.checked) { (map[key] = map[key] || []).push(el.value); }
            } else if (el.type === 'radio') {
                if (el.checked) { map[key] = el.value; }
            } else {
                map[key] = el.value;
            }
        });
        return map;
    }

    function isBlank(v) { return Array.isArray(v) ? v.length === 0 : (v === undefined || v === null || v === ''); }
    function asList(v) { return String(v).split(',').map(function (s) { return s.trim(); }); }
    function has(v, x) { return Array.isArray(v) ? v.map(String).indexOf(String(x)) > -1 : String(v) === String(x); }
    function startsWith(s, prefix) { return String(s).indexOf(String(prefix)) === 0; }
    function endsWith(s, suffix) { var str = String(s), suf = String(suffix); return str.length >= suf.length && str.indexOf(suf, str.length - suf.length) === str.length - suf.length; }

    function evalCondition(c, a) {
        var v = a[c.key];
        switch (c.operator) {
            case 'equals': return has(v, c.value);
            case 'not_equals': return !has(v, c.value);
            case 'in': return Array.isArray(v) ? v.some(function (x) { return asList(c.value).indexOf(String(x)) > -1; }) : asList(c.value).indexOf(String(v == null ? '' : v)) > -1;
            case 'not_in': return !(Array.isArray(v) ? v.some(function (x) { return asList(c.value).indexOf(String(x)) > -1; }) : asList(c.value).indexOf(String(v == null ? '' : v)) > -1);
            case 'contains': return Array.isArray(v) ? has(v, c.value) : String(v == null ? '' : v).indexOf(String(c.value)) > -1;
            case 'starts_with': return Array.isArray(v) ? v.some(function (x) { return startsWith(x, c.value); }) : startsWith(v == null ? '' : v, c.value);
            case 'ends_with': return Array.isArray(v) ? v.some(function (x) { return endsWith(x, c.value); }) : endsWith(v == null ? '' : v, c.value);
            case 'is_answered': return !isBlank(v);
            case 'is_not_answered': return isBlank(v);
            case 'greater_than': return parseFloat(v) > parseFloat(c.value);
            case 'less_than': return parseFloat(v) < parseFloat(c.value);
        }
        return true;
    }

    function evalGroup(g, a) {
        var results = (g.conditions || []).map(function (c) { return evalCondition(c, a); })
            .concat((g.groups || []).map(function (cg) { return evalGroup(cg, a); }));
        if (!results.length) return true;
        return g.operator === 'or' ? results.indexOf(true) > -1 : results.indexOf(false) === -1;
    }

    function refresh() {
        var a = answers();
        form.querySelectorAll('[data-visible-when]').forEach(function (el) {
            var rules = JSON.parse(el.getAttribute('data-visible-when'));
            var show = rules.every(function (g) { return evalGroup(g, a); });
            el.hidden = !show;
            el.querySelectorAll('[name]').forEach(function (inp) { inp.disabled = !show; });
        });
    }

    form.addEventListener('change', refresh);
    form.addEventListener('input', refresh);
    refresh();
})();
</script>
