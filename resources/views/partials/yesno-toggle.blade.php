{{-- Live "pressed" state for QuestionType::YesNo button pairs (see
     questions/question.blade.php's YesNo case) — purely cosmetic: the radio
     input underneath already works without this, so nothing here can affect
     what gets submitted or validated.

     Expects a form with id="registration-form". --}}
<script nonce="{{ $cspNonce ?? '' }}">
(function () {
    var form = document.getElementById('registration-form');
    if (!form) return;

    form.addEventListener('change', function (event) {
        if (!event.target.classList.contains('js-yesno-input')) return;

        var group = event.target.closest('.js-yesno');
        if (!group) return;

        group.querySelectorAll('.js-yesno-btn').forEach(function (label) {
            label.classList.toggle('active', label.querySelector('.js-yesno-input').checked);
        });
    });
})();
</script>
