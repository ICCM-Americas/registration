
<x-registration::admin-card :title="__('registration::admin.arrivals_title')" status-key="arrivals_status" class="mt-3">
    @if ($arrivalsByDay->isEmpty())
        <p class="text-muted mb-0">{{ __('registration::admin.arrivals_none') }}</p>
    @else
        <div class="reg-stat-row">
            @foreach ($arrivalsByDay as $label => $count)
                <div>
                    <div class="iccm-stat-value">{{ $label }}</div>
                    <div class="text-muted">{{ $count }}</div>
                </div>
            @endforeach
        </div>
    @endif
</x-registration::admin-card>
