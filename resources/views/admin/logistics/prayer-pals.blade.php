@extends(config('registration.layout'))

@section('title')
{{ __('registration::admin.prayer_pals_title') }}
@endsection

@section('content')
    @include('registration::partials.report-toolbar', ['reportName' => 'prayer_pals', 'pdfPaper' => $pdfPaper])

    <h1>{{ __('registration::admin.prayer_pals_title') }}</h1>
    <p class="text-muted no-print">{{ __('registration::admin.prayer_pals_intro') }}</p>

    @if (session('prayer_pals_status'))
        <div class="alert alert-success no-print">{{ session('prayer_pals_status') }}</div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger no-print">
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route($routeName('admin.logistics.prayer_pals.settings')) }}" class="no-print mb-3 d-flex flex-wrap align-items-center iccm-gap">
        @csrf
        @method('PUT')
        <label for="label_style" class="font-weight-bold mb-0">{{ __('registration::admin.prayer_pals_label_style') }}</label>
        <select id="label_style" name="label_style" class="form-control form-control-sm w-auto js-auto-submit">
            <option value="number" {{ $labelStyle === 'number' ? 'selected' : '' }}>{{ __('registration::admin.prayer_pals_label_style_number') }}</option>
            <option value="letter" {{ $labelStyle === 'letter' ? 'selected' : '' }}>{{ __('registration::admin.prayer_pals_label_style_letter') }}</option>
        </select>
    </form>

    @php
        // The assign() endpoint (and the drag-and-drop payload below) identify
        // an occupant as "user"/"guest" rather than the stored FQCN, so ids
        // from the two different source tables can never collide.
        $occupantType = fn ($o) => $o instanceof \ConferenceTools\Registration\Models\Guest ? 'guest' : 'user';
        $occupantKey = fn ($o) => $occupantType($o).':'.$o->getKey();

        // Literal per-key calls (a concatenated key would not be seen by the
        // translation-coverage guard — see pricing/index.blade.php's
        // $perDiemModeLabels for the same pattern).
        $genderLabels = ['m' => __('registration::admin.prayer_pals_male'), 'f' => __('registration::admin.prayer_pals_female')];
    @endphp

    @if ($unknownGender->isNotEmpty())
        <div class="alert alert-warning no-print">
            {{ trans_choice('registration::admin.prayer_pals_unknown_gender_notice', $unknownGender->count(), ['count' => $unknownGender->count()]) }}
            {{ $unknownGender->map(fn ($o) => $guestQuestions->occupantFullName($o, $questions))->implode(', ') }}
        </div>
    @endif

    <div
        class="prayer-pals-board"
        data-assign-url="{{ route($routeName('admin.logistics.prayer_pals.assign')) }}"
        data-csrf="{{ csrf_token() }}"
    >
        @foreach ($genders as $gender)
            @php
                $sexGroups = $groups->get($gender->value, collect());
                $sizes = $sexGroups->map(fn ($g) => $g->assignments->count());
                $uneven = $sizes->count() > 1 && ($sizes->max() - $sizes->min() > 1);
                $pool = $unassigned[$gender->value];
            @endphp
            <div class="prayer-pals-column">
                <h2 class="h5">{{ $genderLabels[$gender->value] }}</h2>

                @if ($uneven)
                    <div class="alert alert-warning no-print py-2">
                        {{ __('registration::admin.prayer_pals_uneven_notice', ['min' => $sizes->min(), 'max' => $sizes->max()]) }}
                    </div>
                @endif

                <form method="POST" action="{{ route($routeName('admin.logistics.prayer_pals.groups.store')) }}" class="no-print mb-3">
                    @csrf
                    <input type="hidden" name="sex" value="{{ $gender->value }}">
                    <button type="submit" class="btn btn-sm btn-outline-primary">{{ __('registration::admin.prayer_pals_new_group') }}</button>
                </form>

                <div class="prayer-pals-groups">
                    @foreach ($sexGroups as $group)
                        @php
                            $count = $group->assignments->count();
                            $status = $count < 3 ? 'low' : ($count > 4 ? 'high' : 'ok');
                        @endphp
                        <div class="card prayer-pals-group prayer-pals-group--{{ $status }}">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <strong>{{ __('registration::admin.prayer_pals_group_label', ['label' => $group->label()]) }}</strong>
                                <span class="d-flex align-items-center iccm-gap">
                                    <span class="badge {{ $status === 'ok' ? 'badge-success' : 'badge-warning' }}">
                                        {{ trans_choice('registration::admin.prayer_pals_members_count', $count, ['count' => $count]) }}
                                        @if ($status === 'low')
                                            · {{ __('registration::admin.prayer_pals_needs_more', ['count' => 3 - $count]) }}
                                        @elseif ($status === 'high')
                                            · {{ __('registration::admin.prayer_pals_over_target', ['count' => $count - 4]) }}
                                        @endif
                                    </span>
                                    <form method="POST" action="{{ route($routeName('admin.logistics.prayer_pals.groups.destroy'), $group) }}" class="no-print js-confirm-submit" data-confirm="{{ __('registration::admin.prayer_pals_delete_group_confirm') }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-sm btn-outline-danger" aria-label="{{ __('registration::admin.prayer_pals_delete_group') }}" title="{{ __('registration::admin.prayer_pals_delete_group') }}">&times;</button>
                                    </form>
                                </span>
                            </div>
                            <div class="card-body prayer-pals-dropzone" data-group-id="{{ $group->id }}">
                                @forelse ($group->assignments as $assignment)
                                    @php
                                        $memberType = $assignment->assignable_type === \ConferenceTools\Registration\Models\Guest::class ? 'guest' : 'user';
                                        $member = $byKey->get($assignment->assignable_type.':'.$assignment->assignable_id);
                                    @endphp
                                    <span class="prayer-pals-chip" draggable="true" data-occupant="{{ $memberType }}:{{ $assignment->assignable_id }}">
                                        {{ $member ? $guestQuestions->occupantFullName($member, $questions) : '#'.$assignment->assignable_id }}
                                        @if ($member && ($organization = $questions->organization($member)))
                                            <span class="prayer-pals-chip__org">{{ $organization }}</span>
                                        @endif
                                        <form method="POST" action="{{ route($routeName('admin.logistics.prayer_pals.unassign'), $assignment) }}" class="no-print d-inline">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="prayer-pals-chip__remove" aria-label="{{ __('registration::admin.prayer_pals_remove') }}" title="{{ __('registration::admin.prayer_pals_remove') }}">&times;</button>
                                        </form>
                                    </span>
                                @empty
                                    <p class="text-muted mb-0 no-print small">{{ __('registration::admin.prayer_pals_drop_hint') }}</p>
                                @endforelse
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="card mt-3 no-print">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <strong>{{ __('registration::admin.prayer_pals_unassigned') }}</strong>
                        <span class="text-muted">{{ $pool->count() }}</span>
                    </div>
                    <div class="card-body prayer-pals-pool">
                        @if ($pool->isEmpty())
                            <p class="text-muted mb-0">{{ __('registration::admin.prayer_pals_all_assigned') }}</p>
                        @endif
                        @foreach ($pool as $occupant)
                            <span class="prayer-pals-chip" draggable="true" data-occupant="{{ $occupantKey($occupant) }}">
                                {{ $guestQuestions->occupantFullName($occupant, $questions) }}
                                @if ($organization = $questions->organization($occupant))
                                    <span class="prayer-pals-chip__org">{{ $organization }}</span>
                                @endif
                            </span>
                        @endforeach
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    @include('registration::partials.grouped-pdf-script')

    <script nonce="{{ $cspNonce ?? '' }}">
    (function () {
        document.querySelectorAll('.js-confirm-submit').forEach(function (form) {
            form.addEventListener('submit', function (e) {
                if (!confirm(form.dataset.confirm)) {
                    e.preventDefault();
                }
            });
        });

        document.querySelectorAll('.js-auto-submit').forEach(function (el) {
            el.addEventListener('change', function () {
                el.form.requestSubmit();
            });
        });

        var board = document.querySelector('.prayer-pals-board');
        if (!board) return;

        var assignUrl = board.dataset.assignUrl;
        var csrf = board.dataset.csrf;

        function assign(occupant, groupId) {
            var parts = occupant.split(':');
            fetch(assignUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                body: JSON.stringify({ occupant_type: parts[0], occupant_id: parts[1], prayer_pals_group_id: groupId }),
            }).then(function (response) {
                if (response.ok) {
                    window.location.reload();
                    return;
                }
                response.json().then(function (data) {
                    var message = (data.errors && data.errors.occupant_id && data.errors.occupant_id[0]) || data.message;
                    alert(message || @json(__('registration::admin.prayer_pals_sex_mismatch')));
                }).catch(function () {});
            }).catch(function () {});
        }

        board.querySelectorAll('.prayer-pals-chip[draggable]').forEach(function (chip) {
            chip.addEventListener('dragstart', function (e) {
                e.dataTransfer.setData('text/plain', chip.dataset.occupant);
                e.dataTransfer.effectAllowed = 'move';
            });
        });

        board.querySelectorAll('.prayer-pals-dropzone').forEach(function (zone) {
            zone.addEventListener('dragover', function (e) {
                e.preventDefault();
                zone.classList.add('drag-over');
            });
            zone.addEventListener('dragleave', function () {
                zone.classList.remove('drag-over');
            });
            zone.addEventListener('drop', function (e) {
                e.preventDefault();
                zone.classList.remove('drag-over');
                var occupant = e.dataTransfer.getData('text/plain');
                if (occupant) {
                    assign(occupant, zone.dataset.groupId);
                }
            });
        });
    })();
    </script>
@endsection
