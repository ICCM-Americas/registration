{{-- Data reset (admin dashboard): wipes every registration answer, group,
     draft and room assignment for a clean slate — for a testing pass or to
     clear seeded demo data. Never deletes user accounts. --}}
<x-registration::admin-card :title="__('registration::admin.answers_title')" status-key="answers_status" class="mt-3">
    <p class="text-muted">{{ __('registration::admin.answers_intro') }}</p>

    <form method="POST" action="{{ route($routeName('admin.data.answers.destroy')) }}"
          class="js-confirm-submit" data-confirm="{{ __('registration::admin.answers_confirm') }}">
        @csrf
        @method('DELETE')
        <button type="submit" class="btn btn-danger btn-sm">{{ __('registration::admin.answers_delete') }}</button>
    </form>
</x-registration::admin-card>
